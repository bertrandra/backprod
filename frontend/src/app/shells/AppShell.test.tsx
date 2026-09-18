import { screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { renderAtRoute, stubClient } from '@/test-utils';

import { AppShell } from './AppShell';

/**
 * The shell with no product to open in (2026-09-17).
 *
 * Somebody who asked to join an organisation and is waiting on its
 * administrator has no product yet — `/products` names none but says where
 * they wait — and "choose one in the bar above" would be a door with
 * nothing behind it. They are told they are waiting, and for whom.
 */
const NOBODY_ON_STAFF = {
  status: 403,
  error: { error: { code: 'PERMISSION_DENIED', message: 'No.', details: {}, request_id: 'r' } },
};

function clientFor(pending: { tenant: string; name: string }[]) {
  return stubClient({
    'GET /api/v1/products': { data: { products: [], default: null, pending_memberships: pending } },
    'GET /api/v1/staff/me': NOBODY_ON_STAFF,
  });
}

describe('the landing address, signed in', () => {
  // The product chosen here is remembered in localStorage, and the tests
  // below are about having none.
  afterEach(() => {
    window.localStorage.clear();
    useSessionStore.setState({ productCode: null });
  });

  const MEMBER = {
    user_id: 'u-1',
    email: 'ada@acme.test',
    display_name: 'Ada',
    product_id: 'p-1',
    tenant_id: 't-1',
    roles: ['USER'],
    permissions: ['projects.read', 'billing.read'],
    capabilities: [],
  };

  it('goes to the first screen in the menu, and keeps the product in the address', async () => {
    // Whether they came in by the storefront's link or by a deep link's
    // form, signing in lands on what the rail leads with (2026-09-18).
    const { location } = renderAtRoute(
      <AppShell />,
      stubClient({
        'GET /api/v1/me': { data: MEMBER },
        'GET /api/v1/me/navigation': { data: { hidden: [] } },
        'GET /api/v1/products': { data: { products: [{ id: 'p-1', code: 'atlas', name: 'Atlas' }], default: 'atlas', pending_memberships: [] } },
        'GET /api/v1/staff/me': NOBODY_ON_STAFF,
      }),
      { path: '/', initial: '/?product=atlas' },
    );

    await waitFor(() => expect(location()).toMatch(/^\/projects/));
    expect(location()).toContain('product=atlas');
  });
});

describe('with no product to open in', () => {
  it('says they are waiting, and for whom, when a request is pending', async () => {
    renderAtRoute(<AppShell />, clientFor([{ tenant: 'acme', name: 'Acme Ltd' }]), { path: '/', product: null });

    await waitFor(() => expect(screen.getByTestId('waiting-for-approval')).toBeTruthy());
    expect(screen.getByText('Waiting for Acme Ltd')).toBeTruthy();
    expect(screen.queryByText('No product selected')).toBeNull();
  });

  it('says the ordinary thing when nothing is pending either', async () => {
    renderAtRoute(<AppShell />, clientFor([]), { path: '/', product: null });

    await waitFor(() => expect(screen.getByText('No product selected')).toBeTruthy());
    expect(screen.queryByTestId('waiting-for-approval')).toBeNull();
  });
});
