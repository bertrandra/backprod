import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useConsoleStore } from '@/state/console';
import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { TenantWorkspaceScreen } from './TenantWorkspaceScreen';

/**
 * One customer, read from the console.
 *
 * What is owed: nothing is read before a reason is given; the reason is kept
 * for this customer and attached to every read of it; the product picker
 * narrows every tab; a role without the finance permission is told so
 * rather than shown a 403; and nothing on the screen writes.
 */
const ATLAS = { id: 'p-atlas', code: 'atlas', name: 'Atlas', active: true };
const BOREAS = { id: 'p-boreas', code: 'boreas', name: 'Boreas', active: true };
const TENANT = { id: 't-1', name: 'Acme Ltd', slug: 'acme', may_author_offers: false, products: [ATLAS, BOREAS] };

const ADMIN = {
  staff: {
    user_id: 's-1',
    email: 'sam@demo.test',
    display_name: 'Sam',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.tenants.read', 'admin.finance.read'],
  },
};
const SUPPORT = {
  staff: { user_id: 's-2', email: 'hedy@demo.test', display_name: 'Hedy', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] },
};

const MEMBERS = [
  { user_id: 'u-ada', email: 'ada@acme.test', display_name: 'Ada', roles: ['TENANT_ADMIN'], products: ['atlas', 'boreas'] },
  { user_id: 'u-erased', email: null, display_name: null, roles: ['USER'], products: ['atlas'] },
];

const SUBSCRIPTION = {
  id: 'sub-1', tenant_id: 't-1', tenant_name: 'Acme Ltd', status: 'ACTIVE', started_at: '2026-01-01T00:00:00Z',
  current_period_end: '2026-02-01T00:00:00Z', cancel_at_period_end: false, term_ends_at: null, commitment_ends_at: null,
  offer_code: 'pro-monthly', offer_version: 1, price_minor_units: 4900, currency: 'EUR',
};

const INVOICE = {
  id: 'inv-1', number: '2026-000001', tenant_id: 't-1', tenant_name: 'Acme Ltd', status: 'PAID', currency: 'EUR',
  net_minor_units: 4083, vat_minor_units: 817, gross_minor_units: 4900, issued_at: '2026-01-01T00:00:00Z', due_at: null, paid_at: '2026-01-02T00:00:00Z',
};

function stubs(extra: Stubs = {}): Stubs {
  return {
    'GET /api/v1/staff/me': { data: ADMIN },
    'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    'GET /api/v1/staff/tenants/{tenantId}/members': { data: { members: MEMBERS } },
    'GET /api/v1/admin/subscriptions': { data: { subscriptions: [SUBSCRIPTION], total: 1, limit: 50, offset: 0 } },
    'GET /api/v1/admin/invoices': { data: { invoices: [INVOICE], total: 1, limit: 50, offset: 0 } },
    ...extra,
  };
}

async function giveAMotive(): Promise<void> {
  await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());
  fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'SUPPORT_REQUEST' } });
  fireEvent.change(screen.getByLabelText('Reference'), { target: { value: 'ticket HELP-4182' } });
  fireEvent.click(screen.getByRole('button', { name: /^Open / }));
}

const render = (client: ReturnType<typeof stubClient>, tab?: string) =>
  renderAtRoute(<TenantWorkspaceScreen tenantId="t-1" />, client, {
    path: '/console/tenants/t-1',
    initial: tab === undefined ? '/console/tenants/t-1' : `/console/tenants/t-1?tab=${tab}`,
  });

beforeEach(() => {
  useConsoleStore.setState({ motives: {}, productCode: null });
});

describe('opening a customer', () => {
  it('reads nothing until a reason is given, then keeps it for that customer', async () => {
    const { client, requests } = recordingClient(stubs());
    render(client);

    await waitFor(() => expect(screen.getByTestId('access-motive')).toBeTruthy());
    expect(requests.filter((r) => r.path.includes('/staff/tenants/{'))).toHaveLength(0);

    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tenant-workspace')).toBeTruthy());
    expect(screen.getByRole('heading', { name: 'Acme Ltd' })).toBeTruthy();
    const read = requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}');
    expect((read?.header as Record<string, string> | undefined)?.['X-Access-Purpose']).toBe('SUPPORT_REQUEST');
    // Kept for this customer, and only this one.
    expect(useConsoleStore.getState().motives['t-1']?.reference).toBe('ticket HELP-4182');
    expect(useConsoleStore.getState().motives['t-2']).toBeUndefined();
  });

  it('shows what the customer holds, and points writes back to the list', async () => {
    render(stubClient(stubs()));
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-overview')).toBeTruthy());
    expect(document.querySelector('[data-product="atlas"]')).not.toBeNull();
    expect(document.querySelector('[data-product="boreas"]')).not.toBeNull();
    expect(screen.getByRole('link', { name: 'Tenants list' })).toBeTruthy();
    // Read-only: no button on the screen changes anything.
    expect(screen.queryByRole('button', { name: /assign|remove|save|lend/i })).toBeNull();
  });
});

describe('the members tab', () => {
  it('lists each person with roles and products, erased people by their absence of a name', async () => {
    render(stubClient(stubs()), 'members');
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-members')).toBeTruthy());
    expect(document.querySelector('[data-member="u-ada"]')?.textContent).toContain('TENANT_ADMIN');
    expect(document.querySelector('[data-member="u-ada"]')?.textContent).toContain('atlas, boreas');
    expect(document.querySelector('[data-member="u-erased"]')?.textContent).toContain('Erased');
  });

  it('narrows to the product the bar picked, and sends it', async () => {
    useConsoleStore.setState({ productCode: 'boreas' });
    const { client, requests } = recordingClient(stubs());
    render(client, 'members');
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-members')).toBeTruthy());
    expect(screen.getByTestId('narrowed-to').textContent).toContain('Boreas');
    const read = requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}/members');
    expect(read).toBeDefined();
    expect(read?.query).toEqual({ product: 'boreas' });
  });

  it('ignores a picked product this customer does not hold', async () => {
    useConsoleStore.setState({ productCode: 'delos' });
    const { client, requests } = recordingClient(stubs());
    render(client, 'members');
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-members')).toBeTruthy());
    expect(screen.queryByTestId('narrowed-to')).toBeNull();
    expect(requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}/members')?.query).toEqual({});
  });
});

describe('the finance tabs', () => {
  it('list this customer alone, narrowed by product id when the bar picked one', async () => {
    useConsoleStore.setState({ productCode: 'atlas' });
    const { client, requests } = recordingClient(stubs());
    render(client, 'invoices');
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-invoices')).toBeTruthy());
    expect(screen.getByText('2026-000001')).toBeTruthy();
    const read = requests.find((r) => r.path === '/api/v1/admin/invoices');
    expect(read?.query).toMatchObject({ tenant_id: 't-1', product_id: 'p-atlas' });
  });

  it('show subscriptions with periodicity and commitment apart', async () => {
    render(stubClient(stubs()), 'subscriptions');
    await giveAMotive();

    await waitFor(() => expect(screen.getByTestId('tab-subscriptions')).toBeTruthy());
    expect(document.querySelector('[data-subscription="sub-1"]')?.textContent).toMatch(/period ends/);
    expect(document.querySelector('[data-subscription="sub-1"]')?.textContent).toMatch(/commitment none recorded/);
  });

  it('tell a role without the finance permission why, instead of failing', async () => {
    const { client, requests } = recordingClient(stubs({ 'GET /api/v1/staff/me': { data: SUPPORT } }));
    render(client, 'invoices');
    await giveAMotive();

    await waitFor(() => expect(screen.getByText('Not yours to read')).toBeTruthy());
    expect(requests.find((r) => r.path === '/api/v1/admin/invoices')).toBeUndefined();
  });
});
