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

  it('sets where a product beside the platform lives, and clears it with null', async () => {
    // The list is asked again after the change, and answers with it.
    let patched = false;
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': () => ({ data: { products: [patched ? { ...ATLAS, app_url: 'https://plan.example.test' } : ATLAS, ORBIT] } }),
      'PATCH /api/v1/staff/products/{productId}': () => {
        patched = true;

        return { data: { product: { ...ATLAS, app_url: 'https://plan.example.test' } } };
      },
    });
    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Application address' })[0]).toBeTruthy());
    fireEvent.click(screen.getAllByRole('button', { name: 'Application address' })[0] as HTMLElement);
    fireEvent.change(screen.getByLabelText('Application address'), { target: { value: 'https://plan.example.test' } });
    fireEvent.submit(screen.getByTestId('app-url-form'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    // Only the address: a PATCH says nothing about the name or the flag.
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ app_url: 'https://plan.example.test' });
    await waitFor(() => expect(screen.getByTestId('app-url').textContent).toContain('https://plan.example.test'));
  });

  it('issues a key for the product’s server, shows the bearer once, and revokes', async () => {
    // ADR-051 §4. The list never carries the secret; the answer to issuing does.
    let issued = false;
    const KEY = { id: 'k-1', key_id: 'abc123abc123', label: 'plan production', scopes: ['product.entitlements.read'], created_at: '2026-09-21T08:00:00Z', expires_at: '2027-09-21T08:00:00Z', revoked_at: null, last_used_at: null };
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS, ORBIT] } },
      'GET /api/v1/staff/products/{productId}/credentials': () => ({ data: { credentials: issued ? [KEY] : [] } }),
      'POST /api/v1/staff/products/{productId}/credentials': () => {
        issued = true;

        return { status: 201, data: { credential: KEY, bearer: 'bpk_abc123abc123_secret-secret-secret-secret-secret-s' } };
      },
      'DELETE /api/v1/staff/products/{productId}/credentials/{credentialId}': { data: { credential: { ...KEY, revoked_at: '2026-09-21T09:00:00Z' } } },
    });
    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('keys-atlas')).toBeTruthy());
    fireEvent.click(screen.getByTestId('keys-atlas'));
    await waitFor(() => expect(screen.getByTestId('credentials-p-1')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Key label'), { target: { value: 'plan production' } });
    fireEvent.submit(screen.getByTestId('issue-key-form'));

    await waitFor(() => expect(screen.getByTestId('issued-key')).toBeTruthy());
    expect(screen.getByTestId('issued-key').textContent).toContain('bpk_abc123abc123_');
    expect(requests.find((r) => r.method === 'POST' && r.path.endsWith('/credentials'))?.body).toEqual({ label: 'plan production', scopes: ['product.entitlements.read'] });

    // Listed by its public half only.
    await waitFor(() => expect(screen.getByTestId('credential-list').textContent).toContain('abc123abc123'));
    expect(screen.getByTestId('credential-list').textContent).not.toContain('secret-secret');

    fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
    await waitFor(() => expect(requests.some((r) => r.method === 'DELETE')).toBe(true));
  });

  it('sets the webhook address, issues the secret once, lists deliveries and retries a parked one', async () => {
    // ADR-051 §5. The secret is in the answer to issuing and nowhere else;
    // a parked delivery is the one with a button.
    const PARKED = { id: 'd-1', event_id: 'e-1', event_type: 'member.added', tenant_id: 't-1', occurred_at: '2026-09-21T08:00:00Z', attempt: 6, next_attempt_at: '2026-09-22T08:00:00Z', delivered_at: null, parked_at: '2026-09-22T08:00:00Z', last_status: 503, last_error: 'HTTP_503' };
    const DELIVERED = { ...PARKED, id: 'd-2', event_id: 'e-2', event_type: 'subscription.started', attempt: 1, delivered_at: '2026-09-21T08:00:05Z', parked_at: null, last_status: 200, last_error: null };
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [{ ...ATLAS, webhook_url: null, webhook_secret_issued_at: null }] } },
      'PATCH /api/v1/staff/products/{productId}': { data: { product: { ...ATLAS, webhook_url: 'https://plan.example.test/hook', webhook_secret_issued_at: null } } },
      'POST /api/v1/staff/products/{productId}/webhook-secret': { status: 201, data: { secret: 'bwh_secret-secret-secret-secret-secret-secret-s', product: { ...ATLAS, webhook_url: 'https://plan.example.test/hook', webhook_secret_issued_at: '2026-09-21T09:00:00Z' } } },
      'GET /api/v1/staff/products/{productId}/webhook-deliveries': { data: { deliveries: [PARKED, DELIVERED] } },
      'POST /api/v1/staff/products/{productId}/webhook-deliveries/{deliveryId}/retry': { data: { delivery: { ...PARKED, parked_at: null } } },
    });
    renderAtRoute(<ProductsScreen />, client, { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('webhook-atlas')).toBeTruthy());
    fireEvent.click(screen.getByTestId('webhook-atlas'));
    await waitFor(() => expect(screen.getByTestId('webhook-p-1')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Webhook address'), { target: { value: 'https://plan.example.test/hook' } });
    fireEvent.submit(screen.getByTestId('webhook-url-form'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ webhook_url: 'https://plan.example.test/hook' });

    fireEvent.click(screen.getByRole('button', { name: 'Issue a secret' }));
    await waitFor(() => expect(screen.getByTestId('issued-secret')).toBeTruthy());
    expect(screen.getByTestId('issued-secret').textContent).toContain('bwh_secret');

    const list = await screen.findByTestId('delivery-list');
    expect(list.querySelectorAll('[data-state="parked"]')).toHaveLength(1);
    expect(list.querySelectorAll('[data-state="delivered"]')).toHaveLength(1);
    expect(list.textContent).not.toContain('bwh_');

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(requests.some((r) => r.path.endsWith('/retry'))).toBe(true));
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
