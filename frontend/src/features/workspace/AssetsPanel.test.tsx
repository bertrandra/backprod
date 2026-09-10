import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { AssetsPanel } from './AssetsPanel';

/**
 * The rule this panel exists to keep: **the client never fetches the bytes.**
 *
 * Downloading asks for a signed link and navigates to it, so a large file goes
 * from storage to the browser without passing through this application. The test
 * asserts the navigation, because that is the observable difference between the
 * correct implementation and a `fetch(url)` that would look identical on screen.
 */
const SESSION_WITH_ASSETS = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'assets.read', 'assets.manage', 'projects.write'],
};

function asset(overrides: Record<string, unknown> = {}) {
  return {
    id: 'a-1',
    kind: 'UPLOAD',
    project_id: 'p-1',
    filename: 'plan.dxf',
    content_type: 'application/octet-stream',
    byte_size: 2048,
    checksum: 'abcdef0123456789',
    uploaded_by: 'u-1',
    created_at: '2026-01-01T10:00:00Z',
    ...overrides,
  };
}

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_ASSETS },
    'GET /api/v1/projects/{projectId}/assets': { data: { assets: [asset()] } },
    ...extra,
  });
}

/** Replaces navigation, which jsdom does not implement. */
function watchNavigation() {
  const assign = vi.fn();

  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...window.location, assign },
  });

  return assign;
}

describe('downloading', () => {
  it('creates a signed link and sends the browser to it', async () => {
    const assign = watchNavigation();
    let linked = 0;

    renderWith(
      <AssetsPanel projectId="p-1" />,
      clientFor({
        'POST /api/v1/assets/{assetId}/link': (): Stub => {
          linked += 1;

          return {
            data: { url: 'https://storage.test/signed/abc', expires_at: '2026-01-01T11:00:00Z' },
            status: 201,
          };
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('plan.dxf')).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /^download$/i }));

    await waitFor(() => expect(linked).toBe(1));
    // The signed URL, followed by the browser. Nothing read the bytes here.
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://storage.test/signed/abc'));
  });
});

describe('the row', () => {
  it('shows the type the API sniffed, not the one an upload claimed', async () => {
    // A client that says image/png about a script is not believed, so the row is
    // built from the response rather than from the File.
    renderWith(<AssetsPanel projectId="p-1" />, clientFor());

    await waitFor(() => expect(screen.getByText(/application\/octet-stream/)).toBeTruthy());
    expect(screen.getByText(/abcdef012345/)).toBeTruthy();
  });
});

describe('deleting', () => {
  it('takes two steps, and the second one says it is permanent', async () => {
    let deleted = 0;

    renderWith(
      <AssetsPanel projectId="p-1" />,
      clientFor({
        'DELETE /api/v1/assets/{assetId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('plan.dxf')).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /^delete$/i }));
    expect(deleted).toBe(0);

    fireEvent.click(screen.getByRole('button', { name: /delete for good/i }));
    await waitFor(() => expect(deleted).toBe(1));
  });

  it('can be backed out of', async () => {
    let deleted = 0;

    renderWith(
      <AssetsPanel projectId="p-1" />,
      clientFor({
        'DELETE /api/v1/assets/{assetId}': (): Stub => {
          deleted += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('plan.dxf')).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /^delete$/i }));
    fireEvent.click(screen.getByRole('button', { name: /^keep$/i }));

    expect(deleted).toBe(0);
    expect(screen.getByRole('button', { name: /^delete$/i })).toBeTruthy();
  });
});

describe('exporting', () => {
  it('is queued rather than produced, and says where to watch it', async () => {
    let requested = 0;

    renderWith(
      <AssetsPanel projectId="p-1" />,
      clientFor({
        'POST /api/v1/projects/{projectId}/exports': (): Stub => {
          requested += 1;

          return {
            data: {
              id: 'j-1',
              type: 'export.project',
              status: 'QUEUED',
              payload: { project_id: 'p-1' },
              result: null,
              attempts: 0,
              max_attempts: 3,
              run_after: '2026-01-01T10:00:00Z',
              failure_reason: null,
              started_at: null,
              finished_at: null,
              created_at: '2026-01-01T10:00:00Z',
            },
            status: 202,
          };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /export project/i })).toBeTruthy());
    expect(screen.getByText(/status strip/i)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /export project/i }));

    await waitFor(() => expect(requested).toBe(1));
  });
});
