import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

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

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_PROJECTS },
    'GET /api/v1/projects': listing([project()]),
    'GET /api/v1/products/{productId}/configuration': configuration([7, 9]),
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<ProjectsScreen />, client, { path: '/projects' });

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

describe('the list', () => {
  it('links each project to its own page', async () => {
    const { location } = render(clientFor());

    await waitFor(() => expect(screen.getByText('North wall')).toBeTruthy());

    fireEvent.click(screen.getByText('North wall'));

    await waitFor(() => expect(location()).toContain('/projects/p-1'));
  });

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
