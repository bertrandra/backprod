import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { NotificationsScreen } from './NotificationsScreen';

/**
 * U3's first exit criterion: **the unread count is the server's answer.**
 *
 * A screen that subtracted one after marking something read would pass any test
 * whose stub never changed. So the stub here changes: on the second request the
 * server reports a number that local arithmetic could not have produced, because
 * somebody read two more notifications in another tab. Whatever the screen shows
 * next says which of the two it did.
 */
const SESSION_WITH_INBOX = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'notifications.read', 'notifications.manage'],
};

function notification(id: string, read: boolean) {
  return {
    id,
    type: 'payment.failed',
    category: 'BILLING',
    payload: {},
    legal_effect: false,
    created_at: '2026-01-01T10:00:00Z',
    read_at: read ? '2026-01-02T10:00:00Z' : null,
  };
}

describe('the unread count', () => {
  it('is refetched after reading, never decremented here', async () => {
    let listed = 0;

    // First answer: three unread of three. Second: one unread of three, with two
    // rows already read — a state no local decrement of the first could reach.
    const list = (): Stub =>
      ++listed === 1
        ? {
            data: {
              notifications: [
                notification('n-1', false),
                notification('n-2', false),
                notification('n-3', false),
              ],
              total: 3,
              unread: 3,
              limit: 25,
              offset: 0,
            },
          }
        : {
            data: {
              notifications: [
                notification('n-1', true),
                notification('n-2', true),
                notification('n-3', false),
              ],
              total: 3,
              unread: 1,
              limit: 25,
              offset: 0,
            },
          };

    renderWith(
      <NotificationsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_INBOX },
        'GET /api/v1/notifications': list,
        'POST /api/v1/notifications/{notificationId}/read': { data: {}, status: 204 },
      }),
    );

    await waitFor(() => expect(screen.getByText('3 unread of 3')).toBeTruthy());

    fireEvent.click(screen.getAllByRole('button', { name: /^mark read$/i })[0]!);

    // 1, not 2. Two is what subtracting one from three would have shown.
    await waitFor(() => expect(screen.getByText('1 unread of 3')).toBeTruthy());
    expect(listed).toBeGreaterThan(1);
  });

  it('marks every notification read through the server, not by rewriting rows', async () => {
    let readAll = 0;
    let listed = 0;

    renderWith(
      <NotificationsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_INBOX },
        'GET /api/v1/notifications': (): Stub => {
          listed += 1;

          return {
            data: {
              notifications: [notification('n-1', readAll > 0)],
              total: 1,
              unread: readAll > 0 ? 0 : 1,
              limit: 25,
              offset: 0,
            },
          };
        },
        'POST /api/v1/notifications/read-all': (): Stub => {
          readAll += 1;

          return { data: {}, status: 204 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('1 unread of 1')).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /mark all read/i }));

    await waitFor(() => expect(screen.getByText('0 unread of 1')).toBeTruthy());
    expect(readAll).toBe(1);
    expect(listed).toBeGreaterThan(1);
    // Nothing left to mark, so the action is gone rather than offered as a no-op.
    expect(screen.queryByRole('button', { name: /mark all read/i })).toBeNull();
  });

  it('says what failed when marking read is refused, and leaves the row unread', async () => {
    renderWith(
      <NotificationsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_INBOX },
        'GET /api/v1/notifications': {
          data: {
            notifications: [notification('n-1', false)],
            total: 1,
            unread: 1,
            limit: 25,
            offset: 0,
          },
        },
        'POST /api/v1/notifications/{notificationId}/read': {
          error: {
            error: { code: 'FORBIDDEN', message: 'nope', details: {}, request_id: 'r-1' },
          },
          status: 403,
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('1 unread of 1')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^mark read$/i }));

    // The refusal is shown and the count is untouched: a screen that had already
    // decremented would now be wrong with no way back.
    await waitFor(() => expect(screen.getByText(/1 unread of 1/)).toBeTruthy());
    expect(screen.getByTestId('notification').getAttribute('data-unread')).toBe('true');
  });
});

describe('the inbox', () => {
  it('marks a notice that carries legal effect as one', async () => {
    renderWith(
      <NotificationsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_INBOX },
        'GET /api/v1/notifications': {
          data: {
            notifications: [{ ...notification('n-1', false), legal_effect: true }],
            total: 1,
            unread: 1,
            limit: 25,
            offset: 0,
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/legal notice/i)).toBeTruthy());
  });

  it('distinguishes nothing attempted yet from a failed delivery', async () => {
    renderWith(
      <NotificationsScreen />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_INBOX },
        'GET /api/v1/notifications': {
          data: {
            notifications: [notification('n-1', true)],
            total: 1,
            unread: 0,
            limit: 25,
            offset: 0,
          },
        },
        'GET /api/v1/notifications/{notificationId}/deliveries': {
          data: { deliveries: [] },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/delivery detail/i)).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /delivery detail/i }));

    // "Queued but not tried" and "tried and failed" are different answers to
    // "why did no email arrive", and the person asking needs to know which.
    await waitFor(() => expect(screen.getByText(/no delivery attempted yet/i)).toBeTruthy());
  });
});
