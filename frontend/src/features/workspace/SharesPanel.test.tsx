import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { SharesPanel } from './SharesPanel';

/**
 * Share links (ADR-073). Three rules this panel keeps:
 *
 * - the URL is shown **once**, from the creation's answer, and the list never
 *   carries one;
 * - making a link needs the write permission **and** the feature, and says which
 *   is missing rather than vanishing;
 * - the list and revoking ask for no feature, so somebody whose offer stopped
 *   selling sharing can still take back what they handed out.
 */
const WRITER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'projects.read', 'projects.write'],
  capabilities: [...SESSION.capabilities, 'project_sharing'],
};

function share(overrides: Record<string, unknown> = {}) {
  return {
    id: 's-1',
    project_id: 'p-1',
    display_name: 'Ada',
    recipient_label: 'Le menuisier',
    status: 'ACTIVE',
    created_by: 'u-1',
    created_at: '2026-11-16T10:00:00Z',
    expires_at: '2026-12-16T10:00:00Z',
    revoked_at: null,
    open_count: 2,
    last_opened_at: '2026-11-16T11:00:00Z',
    ...overrides,
  };
}

describe('making a link', () => {
  it('sends what was typed and shows the URL once', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: WRITER },
      'GET /api/v1/projects/{projectId}/shares': { data: { shares: [] } },
      'POST /api/v1/projects/{projectId}/shares': (): Stub => ({
        data: { ...share(), url: 'https://plan.test/?product=plan#share=abc' },
        status: 201,
      }),
    });

    renderWith(<SharesPanel projectId="p-1" />, client);

    await waitFor(() => expect(screen.getByRole('button', { name: /create a link/i })).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/^for/i), { target: { value: '  Le menuisier ' } });
    fireEvent.click(screen.getByRole('button', { name: /create a link/i }));

    await waitFor(() => expect(screen.getByTestId('share-created')).toBeTruthy());
    expect(screen.getByDisplayValue('https://plan.test/?product=plan#share=abc')).toBeTruthy();

    const created = requests.find((request) => request.method === 'POST');
    // A blank sender name goes as null, never as an empty string; 30 days by default.
    expect(created?.body).toEqual({ display_name: null, recipient_label: 'Le menuisier', expires_in_days: 30 });
  });

  it('says the plan does not include it rather than offering a button that refuses', async () => {
    renderWith(
      <SharesPanel projectId="p-1" />,
      stubClient({
        'GET /api/v1/me': { data: { ...WRITER, capabilities: SESSION.capabilities } },
        'GET /api/v1/projects/{projectId}/shares': { data: { shares: [share()] } },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('shares-not-sold')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /create a link/i })).toBeNull();
    // And what was handed out is still listed, and still revocable.
    expect(screen.getByText(/le menuisier/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: /^revoke$/i })).toBeTruthy();
  });
});

describe('the list', () => {
  it('shows the server’s status and offers revoking only a live link', async () => {
    renderWith(
      <SharesPanel projectId="p-1" />,
      stubClient({
        'GET /api/v1/me': { data: WRITER },
        'GET /api/v1/projects/{projectId}/shares': {
          data: { shares: [share({ id: 's-2', status: 'REVOKED', revoked_at: '2026-11-16T12:00:00Z', recipient_label: null })] },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('share-status').textContent).toBe('Revoked'));
    expect(screen.queryByRole('button', { name: /^revoke$/i })).toBeNull();
  });

  it('revokes and asks the server again', async () => {
    let revoked = 0;
    let listed = 0;

    renderWith(
      <SharesPanel projectId="p-1" />,
      stubClient({
        'GET /api/v1/me': { data: WRITER },
        'GET /api/v1/projects/{projectId}/shares': (): Stub => {
          listed += 1;

          return { data: { shares: [share()] } };
        },
        'DELETE /api/v1/projects/{projectId}/shares/{shareId}': (): Stub => {
          revoked += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^revoke$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^revoke$/i }));

    await waitFor(() => expect(revoked).toBe(1));
    await waitFor(() => expect(listed).toBe(2));
  });
});
