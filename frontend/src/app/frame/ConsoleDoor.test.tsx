import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, stubClient } from '@/test-utils';

import { ConsoleDoor } from './ConsoleDoor';

/**
 * The way into the console, for the people who have one.
 *
 * The property under test is the gate, in both directions. It has to appear for
 * platform staff — there was no way in at all before, and the console was
 * reachable only by typing an address nobody can guess. And it has to stay
 * invisible for everybody else, because non-negotiable #22 keeps the two trees
 * separate precisely so no tenant permission reveals a console route.
 */
const ROUTE = { path: '/projects', initial: '/projects' } as const;

const STAFF = {
  staff: {
    user_id: 'u-1',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.self.read', 'staff.products.manage'],
  },
};

describe('somebody who holds a platform role', () => {
  it('is offered the console', async () => {
    renderAtRoute(
      <ConsoleDoor />,
      stubClient({ 'GET /api/v1/staff/me': { data: STAFF } }),
      ROUTE,
    );

    const door = await waitFor(() => screen.getByTestId('console-door'));

    expect(door.getAttribute('href')).toBe('/console');
  });
});

describe('somebody who does not', () => {
  it('is offered nothing when the platform refuses the identity', async () => {
    renderAtRoute(
      <ConsoleDoor />,
      stubClient({
        'GET /api/v1/staff/me': {
          status: 403,
          error: {
            error: {
              code: 'PERMISSION_DENIED',
              message: 'Platform staff are not members of any tenant.',
              details: {},
              request_id: 'req-1',
            },
          },
        },
      }),
      ROUTE,
    );

    // Waited for rather than asserted immediately: a link that appeared and
    // then vanished would pass a synchronous check and still be a leak.
    await waitFor(() => expect(screen.queryByTestId('console-door')).toBeNull());
    expect(screen.queryByTestId('console-door')).toBeNull();
  });

  it('is offered nothing while the answer is still in flight', () => {
    renderAtRoute(
      <ConsoleDoor />,
      stubClient({ 'GET /api/v1/staff/me': { data: STAFF, delayMs: 50 } }),
      ROUTE,
    );

    // Rendering the link optimistically and hiding it on a 403 would show every
    // tenant user a door they cannot open.
    expect(screen.queryByTestId('console-door')).toBeNull();
  });

  it('is offered nothing when the identity carries no role', async () => {
    renderAtRoute(
      <ConsoleDoor />,
      stubClient({
        'GET /api/v1/staff/me': { data: { staff: { user_id: 'u-2', roles: [], permissions: [] } } },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.queryByTestId('console-door')).toBeNull());
  });
});
