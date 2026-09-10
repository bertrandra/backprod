import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProjectScreen } from './ProjectScreen';

/**
 * `workspace.project`, and the roadmap criterion that turned out to be wrong.
 *
 * U4 asks for *"restoring a deleted project works, and the deleted state is
 * visible rather than the row simply vanishing"*. The API has no such thing:
 * `DELETE /projects/{id}` is a hard delete and the versions go with it through
 * `ON DELETE CASCADE`, while `restore` restores a project **to a version**.
 *
 * So what is asserted here is what exists: restore-to-version works, and the
 * delete says what it really does and asks for the name to be typed. The
 * mismatch is recorded in the roadmap rather than hidden behind a test that
 * pretends otherwise.
 */
const SESSION_WITH_PROJECTS = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'projects.read', 'projects.write', 'assets.read'],
};

function project(overrides: Record<string, unknown> = {}) {
  return {
    id: 'p-1',
    name: 'North wall',
    description: 'The scaffolding job',
    schema_version: 7,
    created_by: 'u-1',
    created_at: '2026-01-01T10:00:00Z',
    updated_at: '2026-01-02T10:00:00Z',
    document: {},
    ...overrides,
  };
}

function version(overrides: Record<string, unknown> = {}) {
  return {
    id: 'v-1',
    version_number: 1,
    label: 'Before the change',
    name: 'North wall',
    description: null,
    schema_version: 7,
    created_by: 'u-1',
    created_at: '2026-01-01T12:00:00Z',
    ...overrides,
  };
}

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_PROJECTS },
    'GET /api/v1/projects/{projectId}': { data: project() },
    'GET /api/v1/projects/{projectId}/versions': { data: { versions: [version()] } },
    'GET /api/v1/projects/{projectId}/assets': { data: { assets: [] } },
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<ProjectScreen projectId="p-1" />, client, { path: '/projects/p-1' });

describe('deleting', () => {
  it('says the history goes with it, and needs the name typed', async () => {
    let deleted = 0;

    render(
      clientFor({
        'DELETE /api/v1/projects/{projectId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /delete project/i })).toBeTruthy());

    // The count is real: one snapshot exists, and it is not coming back.
    expect(screen.getByText(/1 snapshot/)).toBeTruthy();
    expect(screen.getByText(/cannot be undone/i)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /delete project/i }));

    const confirm = screen.getByRole<HTMLButtonElement>('button', { name: /delete permanently/i });
    expect(confirm.disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/type/i), { target: { value: 'North wal' } });
    expect(
      screen.getByRole<HTMLButtonElement>('button', { name: /delete permanently/i }).disabled,
    ).toBe(true);

    fireEvent.change(screen.getByLabelText(/type/i), { target: { value: 'North wall' } });
    fireEvent.click(screen.getByRole('button', { name: /delete permanently/i }));

    await waitFor(() => expect(deleted).toBe(1));
  });

  it('can be backed out of', async () => {
    let deleted = 0;

    render(
      clientFor({
        'DELETE /api/v1/projects/{projectId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /delete project/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /delete project/i }));
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

    expect(deleted).toBe(0);
    expect(screen.queryByRole('button', { name: /delete permanently/i })).toBeNull();
  });
});

describe('history', () => {
  it('restores a version, and the version list is refetched afterwards', async () => {
    let restored = 0;
    let versionReads = 0;

    render(
      clientFor({
        'GET /api/v1/projects/{projectId}/versions': (): Stub => {
          versionReads += 1;

          return { data: { versions: [version()] } };
        },
        'POST /api/v1/projects/{projectId}/restore': (): Stub => {
          restored += 1;

          return { data: project({ name: 'North wall (restored)' }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^restore$/i })).toBeTruthy());
    const before = versionReads;

    fireEvent.click(screen.getByRole('button', { name: /^restore$/i }));

    await waitFor(() => expect(restored).toBe(1));
    // Restoring is itself a change the server may record, so the list comes from
    // the server again rather than being assumed unchanged.
    await waitFor(() => expect(versionReads).toBeGreaterThan(before));
  });

  it('says restoring cannot be the step that loses work', async () => {
    // True of the backend: the current state is snapshotted first, in the same
    // transaction. Worth saying, because it is what makes the button safe to press.
    render(clientFor());

    await waitFor(() => expect(screen.getByText(/snapshots the current state first/i)).toBeTruthy());
  });

  it('takes a snapshot with an optional label', async () => {
    let labelSent: unknown = null;

    render(
      clientFor({
        'POST /api/v1/projects/{projectId}/versions': (): Stub => {
          labelSent = true;

          return { data: { ...version(), document: {} }, status: 201 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /take a snapshot/i })).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/^label$/i), { target: { value: 'Before rework' } });
    fireEvent.click(screen.getByRole('button', { name: /take a snapshot/i }));

    await waitFor(() => expect(labelSent).toBe(true));
  });
});

describe('the details form', () => {
  it('is filled from the project once it arrives', async () => {
    // `values` rather than `defaultValues`: the project lands after the first
    // render, and defaults would leave the form permanently empty.
    render(clientFor());

    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>(/^name$/i).value).toBe('North wall'),
    );
    expect(screen.getByLabelText<HTMLInputElement>(/^description$/i).value).toBe(
      'The scaffolding job',
    );
  });

  it('duplicates into the copy rather than staying on the original', async () => {
    const { location } = render(
      clientFor({
        'POST /api/v1/projects/{projectId}/duplicate': {
          data: project({ id: 'p-2', name: 'North wall (copy)' }),
          status: 201,
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /duplicate/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /duplicate/i }));

    await waitFor(() => expect(location()).toContain('/projects/p-2'));
  });
});
