import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProjectsScreen } from './ProjectsScreen';

/**
 * UR5 in the roadmap: *"a product name or plan name reaches a component"* is the
 * frontend copy of what `gate:products` forbids in PHP. A document schema
 * version is the same kind of fact — the backend accepts only what the product
 * has configured — so this screen must read it rather than know it.
 *
 * The stub therefore configures versions the code could not have guessed.
 */
const SESSION_WITH_PROJECTS = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'projects.read', 'projects.write'],
};

const configuration = (supported: unknown): Stub => ({
  data: { configuration: { project_schema_versions: { supported } } },
});

function project(overrides: Record<string, unknown> = {}) {
  return {
    id: 'p-1',
    name: 'North wall',
    description: 'The scaffolding job',
    schema_version: 7,
    created_by: 'u-1',
    created_at: '2026-01-01T10:00:00Z',
    updated_at: '2026-01-02T10:00:00Z',
    ...overrides,
  };
}

const listing = (projects: unknown[]): Stub => ({
  data: { projects, total: projects.length, limit: 25, offset: 0 },
});

/** A product whose screens are this workspace, and one deployed beside it. */
const ATLAS = { id: 'p-1', code: 'atlas', name: 'Atlas', app_url: null };
const PLAN = { id: 'p-plan', code: 'plan', name: 'Plan', app_url: 'https://plan.example.test' };

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_PROJECTS },
    'GET /api/v1/projects': listing([project()]),
    'GET /api/v1/products': { data: { products: [ATLAS, PLAN], default: null, memberships: [], pending: [] } },
    'GET /api/v1/products/{productId}/configuration': configuration([7, 9]),
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>, product = 'atlas') =>
  renderAtRoute(<ProjectsScreen />, client, { path: '/projects', product });

describe('the schema version', () => {
  it('comes from the product, not from this screen', async () => {
    let sent: Record<string, unknown> | null = null;

    render(
      clientFor({
        'POST /api/v1/projects': (): Stub => {
          sent = { seen: true };

          return { data: project({ id: 'p-2' }), status: 201 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/document schema/i)).toBeTruthy());

    // Both configured versions are offered, and the newest is chosen — 9 is a
    // number nothing in the frontend could have known.
    const select = screen.getByLabelText<HTMLSelectElement>(/document schema/i);
    expect([...select.options].map((option) => option.value)).toEqual(['7', '9']);
    expect(select.value).toBe('9');

    fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'South wall' } });
    fireEvent.click(screen.getByRole('button', { name: /create project/i }));

    await waitFor(() => expect(sent).not.toBeNull());
  });

  it('is stated rather than offered when the product accepts only one', async () => {
    render(clientFor({ 'GET /api/v1/products/{productId}/configuration': configuration([4]) }));

    // One choice is not a choice, but the version still ends up on the row, so
    // it is shown rather than hidden.
    await waitFor(() => expect(screen.getByText(/only version this product accepts/i)).toBeTruthy());
    expect(screen.getByText(/v4/)).toBeTruthy();
    expect(screen.queryByLabelText(/document schema/i)).toBeNull();
  });

  it('refuses to offer creation at all when the product configures none', async () => {
    // The backend's intended failure: a product that has declared nothing
    // supports nothing. A form here would produce a 422 every time.
    render(clientFor({ 'GET /api/v1/products/{productId}/configuration': configuration([]) }));

    await waitFor(() =>
      expect(screen.getByText(/accepts no project documents yet/i)).toBeTruthy(),
    );
    expect(screen.queryByRole('button', { name: /create project/i })).toBeNull();
  });

  it('treats a malformed configuration as none rather than guessing', async () => {
    // Free-form by contract, so it can be anything. "Anything" is not a version.
    render(
      clientFor({
        'GET /api/v1/products/{productId}/configuration': {
          data: { configuration: { project_schema_versions: { supported: ['1', 2.5] } } },
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByText(/accepts no project documents yet/i)).toBeTruthy(),
    );
  });
});

describe('a row', () => {
  it('opens the project where it is worked on — here, its own page', async () => {
    // Atlas has no address of its own, so "open" is the project's screen:
    // there is nowhere else to be.
    const { location } = render(clientFor());

    await waitFor(() => expect(screen.getByText('North wall')).toBeTruthy());

    fireEvent.click(screen.getByTestId('open-project'));

    await waitFor(() => expect(location()).toContain('/projects/p-1'));
  });

  it('opens it at the product when the product lives beside the platform', async () => {
    render(clientFor(), 'plan');

    const open = await screen.findByTestId('open-project');

    // A real link, not a button that navigates: it can be opened in a new
    // tab and read before it is followed. And it names the project, so the
    // product opens that one rather than whichever it last remembered.
    const target = new URL(open.getAttribute('href') ?? '');
    expect(target.origin).toBe('https://plan.example.test');
    expect(target.searchParams.get('project')).toBe('p-1');
    expect(target.searchParams.get('product')).toBe('plan');
    expect([...target.searchParams.keys()].sort()).toEqual(['product', 'project']);
  });

  it('keeps the record one click away, whatever the name opens', async () => {
    const { location } = render(clientFor(), 'plan');

    fireEvent.click(await screen.findByTestId('edit-project'));

    await waitFor(() => expect(location()).toContain('/projects/p-1'));
  });

  it('asks before deleting, and deletes only on the second click', async () => {
    let deleted = 0;

    render(
      clientFor({
        'DELETE /api/v1/projects/{projectId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    fireEvent.click(await screen.findByTestId('delete-project'));

    // Nothing has happened yet, and the row says what it is about to do.
    expect(deleted).toBe(0);
    expect(screen.queryByTestId('delete-project')).toBeNull();

    fireEvent.click(screen.getByTestId('confirm-delete'));

    await waitFor(() => expect(deleted).toBe(1));
  });

  it('backs out of the deletion without touching anything', async () => {
    let deleted = 0;

    render(
      clientFor({
        'DELETE /api/v1/projects/{projectId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    fireEvent.click(await screen.findByTestId('delete-project'));
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

    expect(deleted).toBe(0);
    expect(screen.getByTestId('delete-project')).toBeTruthy();
  });
});

describe('the list', () => {

  it('says the workspace is empty without making it look broken', async () => {
    render(clientFor({ 'GET /api/v1/projects': listing([]) }));

    await waitFor(() => expect(screen.getByText(/no projects yet/i)).toBeTruthy());
    // The create form is still there: an empty list is where somebody most needs
    // it. Waited for rather than asserted, because it depends on the product
    // configuration arriving, which is a second request.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /create project/i })).toBeTruthy(),
    );
  });
});

/**
 * R13: the bin.
 *
 * Deleting a project used to destroy its versions through a database cascade,
 * and this screen's delete confirmation said so because it was true. It is no
 * longer true — the project keeps everything and comes back — so there are two
 * lists here, and a deleted project has a date rather than a badge.
 */
describe('the deleted projects', () => {
  it('are a separate list, asked for by the query the contract accepts', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: SESSION_WITH_PROJECTS },
      'GET /api/v1/products/{productId}/configuration': configuration([7]),
      'GET /api/v1/projects': (): Stub => {
        const asked = requests.filter((request) => request.path === '/api/v1/projects');
        const wantsBin =
          (asked[asked.length - 1]?.query as { deleted?: string } | undefined)?.deleted === 'true';

        return wantsBin
          ? listing([project({ id: 'p-2', name: 'Old shed', deleted_at: '2026-02-01T09:00:00Z' })])
          : listing([project({ deleted_at: null })]);
      },
    });

    renderAtRoute(<ProjectsScreen />, client, { path: '/projects' });

    await waitFor(() => expect(screen.getByText('North wall')).toBeTruthy());

    fireEvent.click(screen.getByTestId('toggle-bin'));

    await waitFor(() => expect(screen.getByText('Old shed')).toBeTruthy());

    // The exact string the contract accepts, not `true` as a boolean and not
    // `1`: anything else asks for the live list.
    const queries = requests
      .filter((request) => request.path === '/api/v1/projects')
      .map((request) => (request.query as { deleted?: string } | undefined)?.deleted);

    expect(queries).toContain('true');
    expect(queries).toContain(undefined);
    // And the live project is no longer on screen: two lists, not one merged.
    expect(screen.queryByText('North wall')).toBeNull();
  });

  it('show when each was deleted rather than only that it was', async () => {
    renderAtRoute(
      <ProjectsScreen />,
      clientFor({
        'GET /api/v1/projects': listing([
          project({ name: 'Old shed', deleted_at: '2026-02-01T09:00:00Z' }),
        ]),
      }),
      { path: '/projects' },
    );

    fireEvent.click(await waitFor(() => screen.getByTestId('toggle-bin')));

    await waitFor(() => expect(screen.getByTestId('deleted-at')).toBeTruthy());
    expect(screen.getByTestId('deleted-at').textContent).toMatch(/deleted/i);
    expect(screen.getByTestId('deleted-at').textContent).toMatch(/2026/);
  });

  it('are put back through undelete, not through restore', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: SESSION_WITH_PROJECTS },
      'GET /api/v1/products/{productId}/configuration': configuration([7]),
      'GET /api/v1/projects': listing([
        project({ name: 'Old shed', deleted_at: '2026-02-01T09:00:00Z' }),
      ]),
      'POST /api/v1/projects/{projectId}/undelete': { data: project({ deleted_at: null }) },
    });

    renderAtRoute(<ProjectsScreen />, client, { path: '/projects' });

    fireEvent.click(await waitFor(() => screen.getByTestId('toggle-bin')));
    fireEvent.click(await waitFor(() => screen.getByRole('button', { name: 'Put it back' })));

    await waitFor(() =>
      expect(
        requests.some((request) => request.path === '/api/v1/projects/{projectId}/undelete'),
      ).toBe(true),
    );

    // Never `/restore`: that endpoint restores a project *to a version* and
    // cannot undelete one. R13 was filed partly because the two were confused.
    expect(requests.some((request) => request.path.endsWith('/restore'))).toBe(false);
  });

  it('offer no create form: the bin is somewhere you go, not somewhere you work', async () => {
    renderAtRoute(
      <ProjectsScreen />,
      clientFor({
        'GET /api/v1/projects': listing([
          project({ name: 'Old shed', deleted_at: '2026-02-01T09:00:00Z' }),
        ]),
      }),
      { path: '/projects' },
    );

    fireEvent.click(await waitFor(() => screen.getByTestId('toggle-bin')));

    await waitFor(() => expect(screen.getByText('Old shed')).toBeTruthy());
    expect(screen.queryByRole('button', { name: 'Create project' })).toBeNull();
  });

  it('say what a deleted project still has, when there are none', async () => {
    renderAtRoute(<ProjectsScreen />, clientFor({ 'GET /api/v1/projects': listing([]) }), {
      path: '/projects',
    });

    fireEvent.click(await waitFor(() => screen.getByTestId('toggle-bin')));

    await waitFor(() => expect(screen.getByText('Nothing deleted')).toBeTruthy());
    expect(screen.getByText(/versions, its assets and its jobs intact/i)).toBeTruthy();
  });
});
