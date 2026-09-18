import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { ProductsScreen } from './ProductsScreen';

/**
 * The screen that was missing from the top of the model.
 *
 * What it owes is narrow and specific: show the **code**, because that is what
 * somebody opens this screen to find; show retired products, because they
 * still carry tenants and invoices; and never offer a deletion the platform
 * would have to refuse.
 */
const ATLAS = { id: 'p-1', code: 'atlas', name: 'Atlas', active: true };
const ORBIT = { id: 'p-2', code: 'orbit', name: 'Orbit', active: false };

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/products': { data: { products: [ATLAS, ORBIT] } },
    'POST /api/v1/staff/products': { status: 201, data: { product: { ...ATLAS, id: 'p-3', code: 'nimbus', name: 'Nimbus' } } },
    'PATCH /api/v1/staff/products/{productId}': { data: { product: { ...ATLAS, name: 'Atlas Pro' } } },
    ...extra,
  });
}

describe('the product list', () => {
  it('shows the code, which is what somebody came here for', async () => {
    renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());

    // The value VITE_DEFAULT_PRODUCT and every ?product= link has to match.
    const codes = screen.getAllByTestId('product-code').map((el) => el.textContent);

    expect(codes).toEqual(['atlas', 'orbit']);
  });

  it('shows retired products rather than hiding them', async () => {
    renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());

    // A retired product still carries tenants, subscriptions and invoices. A
    // list that hid it would suggest those had gone too.
    expect(document.querySelector('[data-product="orbit"]')?.getAttribute('data-active')).toBe(
      'false',
    );
    expect(screen.getByTestId('retired')).toBeTruthy();
    expect(screen.getByTestId('retired-note').textContent).toMatch(/records are untouched/i);
  });

  it('says what retiring does before anybody clicks it', async () => {
    renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());

    expect(screen.getByText(/closes every door/i)).toBeTruthy();
    expect(screen.getByText(/nothing here deletes one/i)).toBeTruthy();
  });

  it('offers no way to delete a product', async () => {
    renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());

    // There is no endpoint either — a product carries invoices, and §25 keeps
    // those. A button that had to refuse would teach people the console is
    // unreliable.
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });
});

describe('creating one', () => {
  it('sends a lowercased code with the name', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'POST /api/v1/staff/products': {
        status: 201,
        data: { product: { id: 'p-3', code: 'nimbus', name: 'Nimbus', active: true } },
      },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByLabelText('Code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Code'), { target: { value: '  NIMBUS ' } });
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Nimbus' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'POST')).toBe(true));

    // Capitals are a typing habit, not a different product.
    expect(requests.find((r) => r.method === 'POST')?.body).toEqual({
      code: 'nimbus',
      name: 'Nimbus',
    });
  });

  it('says the code cannot be changed later, before it is chosen', async () => {
    renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByLabelText('Code')).toBeTruthy());

    expect(screen.getByText(/cannot be changed afterwards/i)).toBeTruthy();
    // And that nothing is inherited — a new product is empty, which is not
    // obvious and is expensive to discover later.
    expect(screen.getByText(/nothing is copied/i)).toBeTruthy();
  });

  it('surfaces a taken code instead of looking as though it worked', async () => {
    renderAtRoute(
      <ProductsScreen />,
      clientFor({
        'POST /api/v1/staff/products': {
          status: 409,
          error: {
            error: { code: 'PRODUCT_CODE_TAKEN', message: 'Taken.', details: {}, request_id: 'r' },
          },
        },
      }),
      { path: '/console/products' },
    );

    await waitFor(() => expect(screen.getByLabelText('Code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'atlas' } });
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Atlas Again' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
  });
});

describe('changing one', () => {
  it('renames without saying anything about whether it is active', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'PATCH /api/v1/staff/products/{productId}': {
        data: { product: { ...ATLAS, name: 'Atlas Pro' } },
      },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByRole('button', { name: 'Rename' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Rename' }));

    fireEvent.change(screen.getByLabelText('New name'), { target: { value: 'Atlas Pro' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));

    // Only the field that changed. The endpoint is a PATCH for exactly this:
    // a form that restated `active` could switch a product off by omission.
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ name: 'Atlas Pro' });
  });

  it('retires with the flag and nothing else', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'PATCH /api/v1/staff/products/{productId}': {
        data: { product: { ...ATLAS, active: false } },
      },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByRole('button', { name: 'Retire' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Retire' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));

    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ active: false });
  });

  it('offers to bring a retired product back', async () => {
    renderAtRoute(
      <ProductsScreen />,
      clientFor({ 'GET /api/v1/staff/products': { data: { products: [ORBIT] } } }),
      { path: '/console/products' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: 'Reinstate' })).toBeTruthy());
    expect(screen.queryByRole('button', { name: 'Retire' })).toBeNull();
  });

  it('hands somebody to a product\'s storefront by choosing it in the switcher', async () => {
    const view = renderAtRoute(<ProductsScreen />, clientFor(), {
      path: '/console/products',
      product: 'orbit',
    });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());
    fireEvent.click(screen.getAllByRole('button', { name: 'Storefront' })[0] as HTMLElement);

    // The product being administered is the switcher's (ADR-047): the button
    // chooses it there and goes, and nothing travels in the address.
    await waitFor(() => expect(view.location()).toContain('/console/storefront'));
    expect(view.location()).not.toContain('selected=');
    expect(useSessionStore.getState().productCode).toBe('atlas');
  });
});

describe('the demonstration world', () => {
  const ADMIN = { staff: { user_id: 'u-sam', roles: ['PLATFORM_ADMIN'], permissions: ['staff.products.manage', 'staff.demo.reset'] } };
  const SUPPORT = { staff: { user_id: 'u-hedy', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] } };
  const WORLD = {
    products: [{ code: 'atlas', name: 'Atlas' }, { code: 'boreas', name: 'Boreas' }],
    tenants: [{ slug: 'acme', name: 'Acme Ltd', holds: ['atlas', 'boreas'] }],
    people: [
      { email: 'ada@demo.test', name: 'Ada Lovelace', scope: 'tenant', role: 'TENANT_ADMIN', tenants: ['acme'] },
      { email: 'sam@demo.test', name: 'Sam Staff', scope: 'platform', role: 'PLATFORM_ADMIN', tenants: [] },
    ],
    password: 'demo-password-1234',
    invoices: ['2026-000001'],
  };

  it('is offered only to whoever may reset it', async () => {
    renderAtRoute(
      <ProductsScreen />,
      clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT } }),
      { path: '/console/products' },
    );

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());
    // Hiding is courtesy; the server refuses regardless. But a button that
    // always 403s is a button that should not be there.
    await waitFor(() => expect(screen.queryByTestId('demo-world')).toBeNull());
  });

  it('asks twice, and sends nothing until the second yes', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/me': { data: ADMIN },
      'POST /api/v1/staff/demo/reset': { data: { world: WORLD } },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    expect(screen.getByTestId('demo-world').textContent).toMatch(/including yours/i);

    fireEvent.click(screen.getByTestId('demo-world-arm'));
    expect(screen.getByTestId('demo-world-confirm')).toBeTruthy();
    expect(requests.filter((r) => r.method === 'POST')).toHaveLength(0);

    fireEvent.click(screen.getByRole('button', { name: 'Keep it' }));
    expect(screen.queryByTestId('demo-world-confirm')).toBeNull();
    expect(requests.filter((r) => r.method === 'POST')).toHaveLength(0);
  });

  it('shows who to sign in as, and signs out only when asked', async () => {
    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: Date.now() + 3_600_000 });
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/me': { data: ADMIN },
      'POST /api/v1/staff/demo/reset': { data: { world: WORLD } },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    fireEvent.click(screen.getByTestId('demo-world-arm'));
    fireEvent.click(screen.getByTestId('demo-world-reset'));

    await waitFor(() => expect(screen.getByTestId('demo-world-done')).toBeTruthy());
    expect(requests.filter((r) => r.method === 'POST').map((r) => r.path)).toEqual(['/api/v1/staff/demo/reset']);
    expect(screen.getByTestId('demo-world-people').textContent).toContain('sam@demo.test');
    expect(screen.getByTestId('demo-world-done').textContent).toContain('demo-password-1234');
    // Nothing was refetched: every row the cache described is gone, and a
    // refetch would 401 and sign out under the person reading the answer.
    expect(requests.filter((r) => r.path === '/api/v1/staff/products')).toHaveLength(1);
    expect(useSessionStore.getState().status).toBe('signed-in');

    fireEvent.click(screen.getByTestId('demo-world-sign-in'));
    expect(useSessionStore.getState().status).toBe('anonymous');
  });

  it('says why when the platform is not a demonstration', async () => {
    renderAtRoute(
      <ProductsScreen />,
      clientFor({
        'GET /api/v1/staff/me': { data: ADMIN },
        'POST /api/v1/staff/demo/reset': {
          status: 409,
          error: { error: { code: 'NOT_A_DEMO_DEPLOYMENT', message: "This platform hosts products that are not the demonstration's; resetting would destroy them.", details: { products: ['real-thing'] }, request_id: 'r' } },
        },
      }),
      { path: '/console/products' },
    );

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    fireEvent.click(screen.getByTestId('demo-world-arm'));
    fireEvent.click(screen.getByTestId('demo-world-reset'));

    await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/not the demonstration/i));
    expect(screen.queryByTestId('demo-world-done')).toBeNull();
  });
});

describe('the demonstration page switch', () => {
  const PUBLISHER = { staff: { user_id: 'u-sam', roles: ['PLATFORM_ADMIN'], permissions: ['staff.products.manage', 'staff.demo.publish'] } };
  const SUPPORT = { staff: { user_id: 'u-hedy', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] } };

  it('is offered only to whoever may publish, and reads the switch', async () => {
    renderAtRoute(
      <ProductsScreen />,
      clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT }, 'GET /api/v1/staff/demo/page': { data: { published: true } } }),
      { path: '/console/products' },
    );

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());
    await waitFor(() => expect(screen.queryByTestId('demo-page')).toBeNull());
  });

  it('sends the switch as PUT and shows what the server wrote', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: PUBLISHER },
      'GET /api/v1/staff/products': { data: { products: [] } },
      'GET /api/v1/staff/demo/page': { data: { published: false } },
      'PUT /api/v1/staff/demo/page': { data: { published: true } },
    });

    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId<HTMLInputElement>('demo-page-switch')).toBeTruthy());
    expect(screen.getByTestId<HTMLInputElement>('demo-page-switch').checked).toBe(false);
    expect(screen.getByTestId('demo-page').textContent).toContain('404');

    fireEvent.click(screen.getByTestId('demo-page-switch'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT' && r.path === '/api/v1/staff/demo/page')).toBe(true));
    expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({ published: true });
    await waitFor(() => expect(screen.getByTestId<HTMLInputElement>('demo-page-switch').checked).toBe(true));
    expect(screen.getByTestId('demo-page').textContent).toContain('anybody');
  });
});
