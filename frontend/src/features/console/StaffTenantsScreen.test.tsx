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

/**
 * Answers the motive gate, the way a person does (R14).
 *
 * Every test below that opens a customer or a thread goes through this, because
 * the platform requires a reason and the screen collects it *before* the read.
 * A test that bypassed it would be testing a screen this application does not
 * have.
 */
async function giveAMotive(): Promise<void> {
  await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());

  fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'SUPPORT_REQUEST' } });
  fireEvent.change(screen.getByLabelText('Reference'), { target: { value: 'ticket HELP-4182' } });
  fireEvent.click(screen.getByRole('button', { name: /^Open / }));
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
    await giveAMotive();
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
    await giveAMotive();

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
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('read-recorded')).toBeTruthy());
    expect(screen.getByTestId('read-recorded').textContent).toMatch(/staff\.tenants\.read/);
    expect(screen.getByTestId('read-recorded').textContent).toMatch(/access log/i);
  });

  it('shows what a support agent needs and no more', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });
    await giveAMotive();

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

/**
 * R14: the reason is collected **as part of the read**.
 *
 * U8 shipped a console that said "this read is recorded under
 * `staff.tenants.read`", which was the *authority* for it and all the platform
 * knew. R14 was filed rather than adding a free-text box that went nowhere. These
 * tests are about the difference between a gate and a box: nothing is fetched
 * until there is a reason.
 */
describe('the reason for a read', () => {
  it('is asked for before anything is fetched', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: { staff: { user_id: 's-1', roles: ['SUPPORT'], permissions: ['staff.tenants.read'] } } },
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());

    // The read has *not* happened. A screen that fetched first and asked
    // afterwards would be recording an access it then apologised for.
    expect(requests.filter((r) => r.path === '/api/v1/staff/tenants/{tenantId}')).toHaveLength(0);
    expect(screen.queryByTestId('tenant-detail')).toBeNull();
  });

  it('will not open on a purpose alone, or on a reference too short to mean anything', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());

    const open = () => screen.getByRole<HTMLButtonElement>('button', { name: /^Open / });

    expect(open().disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'INCIDENT' } });
    expect(open().disabled).toBe(true);

    // "x" is not a reason. A field that accepted it would collect nothing while
    // looking like a control, so the button stays disabled.
    fireEvent.change(screen.getByLabelText('Reference'), { target: { value: 'x' } });
    expect(open().disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Reference'), { target: { value: 'INC-2026-14' } });
    expect(open().disabled).toBe(false);
  });

  it('travels with the request as the headers the contract names', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: { staff: { user_id: 's-1', roles: ['SUPPORT'], permissions: ['staff.tenants.read'] } } },
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Purpose'), {
      target: { value: 'BILLING_INVESTIGATION' },
    });
    fireEvent.change(screen.getByLabelText('Reference'), {
      target: { value: 'invoice 2026-000042 disputed' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^Open / }));

    await waitFor(() => expect(screen.getByTestId('tenant-detail')).toBeTruthy());

    // Headers rather than a body: these are GETs, and a GET with a body is a
    // request half the intermediaries between here and the server will drop.
    const read = requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}');

    expect(read?.header).toEqual({
      'X-Access-Purpose': 'BILLING_INVESTIGATION',
      'X-Access-Reason': 'invoice 2026-000042 disputed',
    });
  });

  it('is shown back, so nobody forgets what they are reading under', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('motive-in-effect')).toBeTruthy());
    expect(screen.getByTestId('motive-in-effect').getAttribute('data-purpose')).toBe(
      'SUPPORT_REQUEST',
    );
    expect(screen.getByTestId('motive-in-effect').textContent).toMatch(/HELP-4182/);
  });

  it('says who will see it, before the fields rather than after', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());

    const gate = screen.getByTestId('access-motive').textContent ?? '';

    expect(gate).toMatch(/Nothing is read until you answer/i);
    expect(gate).toMatch(/access log your colleagues can read/i);
  });
});
