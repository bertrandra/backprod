import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { StaffTenantsScreen } from './StaffTenantsScreen';

/**
 * Crossing the boundary, visibly.
 *
 * The trace is the backend's and is not optional. What this screen owes is that
 * nobody is surprised by it — so the warning is asserted *before* the click and
 * the confirmation after it, and both name the permission the read was made
 * under rather than saying "this is logged" and leaving the grounds vague.
 */
const TENANT = { id: 't-1', name: 'Acme Ltd', slug: 'acme' };
const OTHER = { id: 't-2', name: 'Globex', slug: 'globex' };

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/tenants': {
      data: { tenants: [TENANT, OTHER], total: 2, limit: 25, offset: 0 },
    },
    'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    ...extra,
  });
}

describe('before a tenant is opened', () => {
  it('says the read will be recorded, and under what', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), { path: '/console/tenants' });

    await waitFor(() => expect(screen.getByText(/records an entry against your name/i)).toBeTruthy());
    expect(screen.getByText(/with the permission you used/i)).toBeTruthy();
  });

  it('does not read one just because the list loaded', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, { path: '/console/tenants' });

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());

    // Listing is one crossing; opening a company is another. A detail fetched
    // eagerly would file an access entry nobody asked for.
    expect(requests.filter((r) => r.path === '/api/v1/staff/tenants/{tenantId}')).toHaveLength(0);
  });
});

describe('opening a tenant', () => {
  it('puts it in the URL, so a handover is a link', async () => {
    const view = renderAtRoute(<StaffTenantsScreen />, clientFor(), { path: '/console/tenants' });

    await waitFor(() => expect(document.querySelector(`[data-tenant="${TENANT.id}"]`)).not.toBeNull());
    fireEvent.click(document.querySelector(`[data-tenant="${TENANT.id}"]`) as HTMLElement);

    await waitFor(() => expect(view.location()).toContain(`selected=${TENANT.id}`));
    await waitFor(() => expect(screen.getByTestId('tenant-detail')).toBeTruthy());
  });

  it('names the tenant in the path rather than in a header', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('tenant-detail')).toBeTruthy());

    const read = requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}');

    expect(read).toBeDefined();
    // No ambient headers on a staff read: there is no tenant this person
    // belongs to, so there is none to send.
    expect(read?.query).toBeUndefined();
  });

  it('confirms afterwards that the read was recorded', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('read-recorded')).toBeTruthy());
    expect(screen.getByTestId('read-recorded').textContent).toMatch(/staff\.tenants\.read/);
    expect(screen.getByTestId('read-recorded').textContent).toMatch(/access log/i);
  });

  it('shows what a support agent needs and no more', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('tenant-detail')).toBeTruthy());

    const detail = screen.getByTestId('tenant-detail');

    // Enough to confirm it is the right company. Reading its data would be a
    // crossing the contract has not authorised, and there is no endpoint for it.
    expect(detail.textContent).toContain('Acme Ltd');
    expect(detail.textContent).toContain('acme');
    expect(detail.textContent).toContain('t-1');
  });
});

describe('the list', () => {
  it('reports the counted total', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        'GET /api/v1/staff/tenants': {
          data: { tenants: [TENANT], total: 137, limit: 25, offset: 0 },
        },
      }),
      { path: '/console/tenants' },
    );

    await waitFor(() =>
      expect(screen.getByTestId('tenant-count').textContent).toBe('Showing 1 of 137.'),
    );
  });
});
