import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { NotificationSettingsScreen } from './NotificationSettingsScreen';

/**
 * Two rules from non-negotiable #24, asserted rather than assumed.
 *
 *   - a security notification cannot be switched off, and the screen says so
 *     instead of hiding the row;
 *   - revoking a consent leaves the row, because the record that permission once
 *     existed *is* the proof, and deleting it would destroy the only evidence.
 *
 * The second one is why the revocation test asserts the row is still listed. A
 * screen that removed it would look tidier and lose the audit.
 */
const SESSION_WITH_SETTINGS = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'notifications.read', 'notifications.manage'],
};

const PREFERENCES = {
  'BILLING.EMAIL': true,
  'MARKETING.EMAIL': false,
  'SECURITY.EMAIL': true,
};

function consent(overrides: Record<string, unknown> = {}) {
  return {
    id: 'c-1',
    channel: 'SMS',
    purpose: 'delivery alerts',
    granted_at: '2026-01-01T10:00:00Z',
    revoked_at: null,
    source: 'signup form',
    live: true,
    ...overrides,
  };
}

describe('a security notification', () => {
  it('is on, and its control is fixed rather than absent', async () => {
    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': { data: { preferences: PREFERENCES } },
        'GET /api/v1/notifications/consents': { data: { consents: [] } },
      }),
    );

    const box = await waitFor(() =>
      screen.getByLabelText<HTMLInputElement>('SECURITY on EMAIL'),
    );

    // Present, checked, and refusing to change: someone hunting for the switch
    // learns it does not exist instead of deciding the page is broken.
    expect(box.checked).toBe(true);
    expect(box.disabled).toBe(true);
    expect(screen.getByText(/always on/i)).toBeTruthy();
  });

  it('is on even when the stored preference says otherwise', async () => {
    // The backend refuses to disable it, so a stored `false` can only be stale or
    // wrong. The screen shows the rule, not the row.
    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': {
          data: { preferences: { ...PREFERENCES, 'SECURITY.EMAIL': false } },
        },
        'GET /api/v1/notifications/consents': { data: { consents: [] } },
      }),
    );

    const box = await waitFor(() =>
      screen.getByLabelText<HTMLInputElement>('SECURITY on EMAIL'),
    );

    expect(box.checked).toBe(true);
  });

  it('is the only category that cannot be switched off', async () => {
    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': { data: { preferences: PREFERENCES } },
        'GET /api/v1/notifications/consents': { data: { consents: [] } },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText('BILLING on EMAIL')).toBeTruthy());

    for (const category of ['BILLING', 'ACCOUNT', 'SUPPORT', 'MARKETING']) {
      expect(
        screen.getByLabelText<HTMLInputElement>(`${category} on EMAIL`).disabled,
      ).toBe(false);
    }
  });
});

describe('a preference', () => {
  it('is written from the response, which is the whole map', async () => {
    let saved: unknown = null;

    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': { data: { preferences: PREFERENCES } },
        'GET /api/v1/notifications/consents': { data: { consents: [] } },
        'PUT /api/v1/notifications/preferences': (): Stub => {
          saved = true;

          // The server's answer, and it disagrees with what was clicked on one
          // other key — so a screen writing this map shows both changes and a
          // screen toggling one checkbox shows only its own.
          return {
            data: { preferences: { ...PREFERENCES, 'MARKETING.EMAIL': true, 'BILLING.EMAIL': false } },
          };
        },
      }),
    );

    const marketing = await waitFor(() =>
      screen.getByLabelText<HTMLInputElement>('MARKETING on EMAIL'),
    );
    expect(marketing.checked).toBe(false);

    fireEvent.click(marketing);

    await waitFor(() => expect(saved).toBe(true));
    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>('MARKETING on EMAIL').checked).toBe(true),
    );
    // The other key the server changed, which local toggling could not know about.
    expect(screen.getByLabelText<HTMLInputElement>('BILLING on EMAIL').checked).toBe(false);
  });
});

describe('a consent', () => {
  it('is revoked without a reload, and its row stays as the proof', async () => {
    let revoked = 0;

    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': { data: { preferences: PREFERENCES } },
        'GET /api/v1/notifications/consents': (): Stub => ({
          data: {
            consents: [
              revoked === 0
                ? consent()
                : consent({ live: false, revoked_at: '2026-02-01T10:00:00Z' }),
            ],
          },
        }),
        'DELETE /api/v1/notifications/consents/{consentId}': (): Stub => {
          revoked += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    const row = await waitFor(() => screen.getByTestId('consent'));
    expect(row.getAttribute('data-live')).toBe('true');

    fireEvent.click(screen.getByRole('button', { name: /revoke/i }));

    await waitFor(() =>
      expect(screen.getByTestId('consent').getAttribute('data-live')).toBe('false'),
    );

    // Still one row, now marked revoked, with the date the API recorded — and no
    // longer offering a revoke it would refuse.
    expect(screen.getAllByTestId('consent')).toHaveLength(1);
    expect(screen.getByTestId('consent').textContent).toMatch(/revoked/i);
    expect(screen.getByTestId('consent').textContent).toContain('delivery alerts');
    expect(screen.queryByRole('button', { name: /^revoke$/i })).toBeNull();
  });

  it('cannot be granted without recording where the opt-in came from', async () => {
    let granted = 0;

    renderWith(
      <NotificationSettingsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_SETTINGS },
        'GET /api/v1/notifications/preferences': { data: { preferences: PREFERENCES } },
        'GET /api/v1/notifications/consents': { data: { consents: [] } },
        'POST /api/v1/notifications/consents': (): Stub => {
          granted += 1;

          return { data: {}, status: 201 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/purpose/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/purpose/i), { target: { value: 'delivery alerts' } });
    fireEvent.click(screen.getByRole('button', { name: /grant consent/i }));

    // Refused here rather than by the API: the evidence is the point of the
    // record, so a consent with no source is not a consent.
    await waitFor(() => expect(screen.getByText(/where the opt-in came from\./i)).toBeTruthy());
    expect(granted).toBe(0);

    fireEvent.change(screen.getByLabelText(/^source$/i), { target: { value: 'signup form' } });
    fireEvent.click(screen.getByRole('button', { name: /grant consent/i }));

    await waitFor(() => expect(granted).toBe(1));
  });
});
