import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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
