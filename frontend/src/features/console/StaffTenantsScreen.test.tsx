import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { StaffTenantsScreen } from './StaffTenantsScreen';

/**
 * A customer as support sees them, opened directly — no reason is asked and
 * nothing is recorded (ADR-069, 2026-10-05).
 */
const ATLAS = { id: 'p-atlas', code: 'atlas', name: 'Atlas', active: true };
const BOREAS = { id: 'p-boreas', code: 'boreas', name: 'Boreas', active: true };
const COMET = { id: 'p-comet', code: 'comet', name: 'Comet', active: false };

const TENANT = { id: 't-1', name: 'Acme Ltd', slug: 'acme', may_author_offers: false, products: [ATLAS] };

const PRO = { id: 'plan-pro', code: 'pro', name: 'Pro', rank: 20 };
const ADVANCED = { id: 'f-1', code: 'advanced_3d', name: 'Advanced 3D', kind: 'BOOLEAN', unit: null };
const PROJECTS = { id: 'f-2', code: 'max_projects', name: 'Projects', kind: 'QUOTA', unit: 'projects' };
const GRANTED = {
  tenant_id: 't-1',
  product_id: 'p-atlas',
  features: [
    { code: 'advanced_3d', name: 'Advanced 3D', kind: 'BOOLEAN', limit: null },
    { code: 'max_projects', name: 'Projects', kind: 'QUOTA', limit: 10 },
  ],
  valid_until: '2027-01-31T23:59:59Z',
  granted_by: 's-1',
  granted_at: '2026-09-17T10:00:00Z',
};
const OTHER = { id: 't-2', name: 'Globex', slug: 'globex', may_author_offers: false, products: [] };

/** An administrator: the only staff identity that may change the flag. */
const ADMIN = {
  staff: {
    user_id: 's-1',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.tenants.read', 'staff.tenants.manage'],
  },
};

/** Support: may open a tenant, may not decide what it is allowed to do. */
const SUPPORT = {
  staff: { user_id: 's-2', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] },
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/me': { data: ADMIN },
    'GET /api/v1/staff/tenants': {
      data: { tenants: [TENANT, OTHER], total: 2, limit: 25, offset: 0 },
    },
    'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    'PUT /api/v1/staff/tenants/{tenantId}/offer-authoring': {
      data: { tenant: { ...TENANT, may_author_offers: true } },
    },
    'GET /api/v1/staff/products': { data: { products: [ATLAS, BOREAS, COMET] } },
    'PUT /api/v1/staff/tenants/{tenantId}/products/{productId}': {
      data: { tenant: { ...TENANT, products: [ATLAS, BOREAS] } },
    },
    'DELETE /api/v1/staff/tenants/{tenantId}/products/{productId}': {
      data: { tenant: { ...TENANT, products: [] } },
    },
    'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: null } },
    'GET /api/v1/staff/catalogue': { data: { product: ATLAS, plans: [PRO], features: [ADVANCED, PROJECTS] } },
    ...extra,
  });
}

describe('before a tenant is opened', () => {
  it('does not read one just because the list loaded', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, { path: '/console/tenants' });

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());

    // Listing is one read; opening a company is another, made when somebody
    // chooses it.
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

  it('opens with no reason asked, and sends none', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    // Straight to the customer (ADR-069): nothing to fill in first.
    await waitFor(() => expect(screen.getByTestId('tenant-detail')).toBeTruthy());
    expect(screen.queryByLabelText('Purpose')).toBeNull();
    expect(requests.find((r) => r.path === '/api/v1/staff/tenants/{tenantId}')?.header).toBeUndefined();
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

/**
 * Lending the catalogue, and the two people who see it differently.
 *
 * The flag is not a display preference. `catalog.manage` is resolved from it, so
 * a tenant role that names the permission grants nothing while it is off — which
 * is why the screen states the consequence rather than labelling a switch, and
 * why the control is behind a permission of its own.
 */
describe('offer authoring', () => {
  it('says a tenant uses the platform catalogue when the flag is off', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('offer-authoring')).toBeTruthy());

    const panel = screen.getByTestId('offer-authoring');

    expect(panel.getAttribute('data-may-author')).toBe('false');
    // The consequence, not the switch: a tenant role naming catalog.manage
    // grants nothing while this is off, and somebody deciding has to know that.
    expect(panel.textContent).toMatch(/whatever their tenant role says/i);
  });

  it('offers to lend it, and sends the state rather than a toggle', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/tenants': {
        data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
      'PUT /api/v1/staff/tenants/{tenantId}/offer-authoring': {
        data: { tenant: { ...TENANT, may_author_offers: true } },
      },
    });

    renderAtRoute(<StaffTenantsScreen />, client, {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Allow offer authoring/i })).toBeTruthy(),
    );
    fireEvent.click(screen.getByRole('button', { name: /Allow offer authoring/i }));

    await waitFor(() =>
      expect(
        requests.filter((r) => r.path === '/api/v1/staff/tenants/{tenantId}/offer-authoring'),
      ).toHaveLength(1),
    );

    const write = requests.find(
      (r) => r.path === '/api/v1/staff/tenants/{tenantId}/offer-authoring',
    );

    // A desired state, so clicking twice on a slow connection asks for the same
    // thing twice rather than undoing the first click.
    expect(write?.body).toEqual({ may_author_offers: true });
    expect(write?.header).toBeUndefined();
  });

  it('offers to take it back once it is lent', async () => {
    const lent = { ...TENANT, may_author_offers: true };

    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({ 'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: lent } } }),
      { path: '/console/tenants', initial: `/console/tenants?selected=${TENANT.id}` },
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Withdraw offer authoring/i })).toBeTruthy(),
    );

    expect(screen.getByTestId('offer-authoring').getAttribute('data-may-author')).toBe('true');
  });

  it('shows support the answer without the means to change it', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT } }), {
      path: '/console/tenants',
      initial: `/console/tenants?selected=${TENANT.id}`,
    });

    await waitFor(() => expect(screen.getByTestId('offer-authoring-readonly')).toBeTruthy());

    // Answering "can they edit their prices?" is support's job. Deciding it is
    // not, and a button that appeared and then answered 403 would teach nobody
    // that.
    expect(screen.getByTestId('offer-authoring').textContent).toMatch(/platform catalogue/i);
    expect(screen.queryByRole('button', { name: /offer authoring/i })).toBeNull();
    expect(screen.getByTestId('offer-authoring-readonly').textContent).toMatch(
      /staff\.tenants\.manage/,
    );
  });

  it('surfaces a refusal instead of leaving the panel looking changed', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        'PUT /api/v1/staff/tenants/{tenantId}/offer-authoring': {
          status: 403,
          error: { error: { code: 'FORBIDDEN', message: 'Not permitted.' } },
        },
      }),
      { path: '/console/tenants', initial: `/console/tenants?selected=${TENANT.id}` },
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Allow offer authoring/i })).toBeTruthy(),
    );
    fireEvent.click(screen.getByRole('button', { name: /Allow offer authoring/i }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(screen.getByTestId('offer-authoring').getAttribute('data-may-author')).toBe('false');
  });
});

/**
 * Which products a tenant holds, decided here (ADR-047).
 *
 * The same shape as offer authoring below it: a fact support may read, a
 * decision only an administrator may make, and a refusal the panel shows rather
 * than hides. The checkbox is the state the server last reported, never the
 * state somebody just asked for.
 */
describe('products held', () => {
  const open = { path: '/console/tenants', initial: `/console/tenants?selected=${TENANT.id}` } as const;

  const PRODUCTS_PATH = '/api/v1/staff/tenants/{tenantId}/products/{productId}';

  it('shows every product the platform offers, ticking the ones held', async () => {
    renderAtRoute(<StaffTenantsScreen />, clientFor(), open);

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(2));

    const boxes = screen.getAllByRole<HTMLInputElement>('checkbox');

    // Atlas held, Boreas offered, Comet retired and not held: not offered.
    expect(boxes.map((box) => [box.getAttribute('data-product'), box.checked])).toEqual([
      ['atlas', true],
      ['boreas', false],
    ]);
  });

  it('lists a retired product the tenant still holds, and nothing retired it does not', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: { ...TENANT, products: [ATLAS, COMET] } } },
      }),
      open,
    );

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(3));

    const comet = screen.getAllByRole<HTMLInputElement>('checkbox').find((box) => box.getAttribute('data-product') === 'comet');

    // A fact about the tenant, shown; and un-ticking it is still allowed —
    // withdrawing a retired product is a tidy-up, assigning one is not.
    expect(comet?.checked).toBe(true);
    expect(comet?.disabled).toBe(false);
    expect(screen.getByTestId('tenant-products').textContent).toMatch(/retired/i);
  });

  it('assigns with PUT and withdraws with DELETE, and re-reads rather than guessing', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
      'GET /api/v1/staff/products': { data: { products: [ATLAS, BOREAS] } },
      [`PUT ${PRODUCTS_PATH}`]: { data: { tenant: { ...TENANT, products: [ATLAS, BOREAS] } } },
      [`DELETE ${PRODUCTS_PATH}`]: { data: { tenant: { ...TENANT, products: [BOREAS] } } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, open);

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(2));

    const box = (code: string) =>
      screen.getAllByRole<HTMLInputElement>('checkbox').find((b) => b.getAttribute('data-product') === code) as HTMLInputElement;

    fireEvent.click(box('boreas'));

    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT' && r.path === PRODUCTS_PATH)).toHaveLength(1));
    // No body: the address is the desired state.
    const put = requests.find((r) => r.method === 'PUT' && r.path === PRODUCTS_PATH);
    expect(put?.body).toBeUndefined();
    expect(put?.header).toBeUndefined();

    fireEvent.click(box('atlas'));

    await waitFor(() => expect(requests.filter((r) => r.method === 'DELETE' && r.path === PRODUCTS_PATH)).toHaveLength(1));
  });

  it('shows the refusal and leaves the box where the server left it', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        [`DELETE ${PRODUCTS_PATH}`]: {
          status: 409,
          error: { error: { code: 'PRODUCT_IN_USE', message: 'A subscription on this product is still owed service.' } },
        },
      }),
      open,
    );

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(2));

    const atlas = screen.getAllByRole<HTMLInputElement>('checkbox').find((b) => b.getAttribute('data-product') === 'atlas') as HTMLInputElement;
    fireEvent.click(atlas);

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    // Still held: the customer paid for it, and the box says what is true.
    expect(atlas.checked).toBe(true);
  });

  it('shows support what is held without the means to change it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: SUPPORT },
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, open);

    await waitFor(() => expect(screen.getAllByRole('checkbox')).toHaveLength(1));
    expect(screen.getByTestId('tenant-products-readonly')).toBeTruthy();

    // The held list, and only that: support is never shown the platform's
    // list as a set of boxes it cannot tick — and never asks for it.
    const boxes = screen.getAllByRole<HTMLInputElement>('checkbox');
    expect(boxes.map((box) => box.getAttribute('data-product'))).toEqual(['atlas']);
    expect(boxes.every((box) => box.disabled)).toBe(true);
    expect(requests.some((r) => r.path === '/api/v1/staff/products')).toBe(false);
  });
});

/**
 * What the platform gives without a sale (docs/tenant-roots.md §2.8): one
 * panel per held product, the whole grant sent as one PUT, nothing assumed
 * before the server answers.
 */
describe('the entitlement the platform gives', () => {
  const open = { path: '/console/tenants', initial: `/console/tenants?selected=${TENANT.id}` } as const;

  it('shows nothing given as exactly that, and a grant with its features, expiry and grantor', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: GRANTED } },
      }),
      open,
    );

    await waitFor(() => expect(screen.getByTestId('entitlement-atlas').getAttribute('data-granted')).toBe('true'));
    expect(screen.getByTestId('granted-atlas').textContent).toContain('Projects');
    expect(screen.getByTestId('granted-atlas').textContent).toContain('10');
    expect(screen.getByTestId('entitlement-atlas').textContent).toContain('Until 2027-01-31');
    expect(screen.getByTestId('entitlement-atlas').textContent).toContain('s-1');
  });

  it('sends the whole grant as one PUT — plan, ticked features with limits, expiry', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: null } },
      'GET /api/v1/staff/catalogue': { data: { product: ATLAS, plans: [PRO], features: [ADVANCED, PROJECTS] } },
      'PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: GRANTED } },
    });

    renderAtRoute(<StaffTenantsScreen />, client, open);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Grant' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Grant' }));

    await waitFor(() => expect(screen.getByTestId('grant-form-atlas')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Start from a plan'), { target: { value: 'pro' } });
    fireEvent.click(screen.getByLabelText('Projects'));
    fireEvent.change(screen.getByLabelText('Projects limit'), { target: { value: '50' } });
    fireEvent.change(screen.getByLabelText('Until'), { target: { value: '2027-01-31' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save grant' }));

    await waitFor(() =>
      expect(requests.some((r) => r.method === 'PUT' && r.path.endsWith('/entitlement'))).toBe(true),
    );
    expect(requests.find((r) => r.method === 'PUT' && r.path.endsWith('/entitlement'))?.body).toEqual({
      plan: 'pro',
      features: [{ code: 'max_projects', limit: 50 }],
      // Unticked, and said: a grant adds a feature and opens no workspace
      // unless somebody chose otherwise (2026-09-25). Sending nothing would
      // be the same default, and this asserts it was a decision.
      covers_people: false,
      valid_until: '2027-01-31T23:59:59.000Z',
    });

    // The answer, not the form: what the server wrote is what is shown.
    await waitFor(() => expect(screen.getByTestId('entitlement-atlas').getAttribute('data-granted')).toBe('true'));
  });

  /**
   * The one choice on that form that changes what people can *do*.
   *
   * Without it a grant lights the features and every workspace still
   * refuses, which is right for restoring a missing capability and wrong for
   * a trial. The server decides on this field; the form has to send it.
   */
  it('sends a trial when the platform says the people may use the product', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: null } },
      'GET /api/v1/staff/catalogue': { data: { product: ATLAS, plans: [PRO], features: [ADVANCED, PROJECTS] } },
      'PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': {
        data: { entitlement: { ...GRANTED, covers_people: true } },
      },
    });

    renderAtRoute(<StaffTenantsScreen />, client, open);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Grant' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Grant' }));

    await waitFor(() => expect(screen.getByTestId('grant-covers-atlas')).toBeTruthy());
    fireEvent.click(screen.getByTestId('grant-covers-atlas'));
    fireEvent.click(screen.getByLabelText('Projects'));
    fireEvent.click(screen.getByRole('button', { name: 'Save grant' }));

    await waitFor(() =>
      expect(requests.some((r) => r.method === 'PUT' && r.path.endsWith('/entitlement'))).toBe(true),
    );

    const sent = requests.find((r) => r.method === 'PUT' && r.path.endsWith('/entitlement'))?.body;
    expect((sent as { covers_people?: boolean } | undefined)?.covers_people).toBe(true);

    // And the answer says which kind it is, because the two look identical
    // on every other field.
    await waitFor(() =>
      expect(screen.getByTestId('grant-kind-atlas').textContent).toMatch(/people may use it/i),
    );
  });

  it('withdraws with DELETE and asks again rather than assuming nothing', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/tenants': { data: { tenants: [TENANT], total: 1, limit: 25, offset: 0 } },
      'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: TENANT } },
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: GRANTED } },
      'DELETE /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { status: 204, data: {} },
    });

    renderAtRoute(<StaffTenantsScreen />, client, open);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Withdraw' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'DELETE' && r.path.endsWith('/entitlement'))).toBe(true));
    await waitFor(() =>
      expect(requests.filter((r) => r.method === 'GET' && r.path.endsWith('/entitlement')).length).toBeGreaterThan(1),
    );
  });

  it('shows support the grant and offers nothing to change it', async () => {
    renderAtRoute(
      <StaffTenantsScreen />,
      clientFor({
        'GET /api/v1/staff/me': { data: SUPPORT },
        'GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement': { data: { entitlement: GRANTED } },
      }),
      open,
    );

    await waitFor(() => expect(screen.getByTestId('granted-atlas')).toBeTruthy());
    const panel = within(screen.getByTestId('entitlement-atlas'));
    expect(panel.queryByRole('button', { name: 'Change' })).toBeNull();
    expect(panel.queryByRole('button', { name: 'Withdraw' })).toBeNull();
  });
});