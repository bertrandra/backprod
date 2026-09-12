import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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

  it('links each product to its own storefront, by code', async () => {
    const view = renderAtRoute(<ProductsScreen />, clientFor(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByTestId('product-list')).toBeTruthy());
    fireEvent.click(screen.getAllByRole('button', { name: 'Storefront' })[0] as HTMLElement);

    // The console has no ambient product, so the one being administered
    // travels in the URL — and a link opens the same one for whoever follows
    // it.
    await waitFor(() => expect(view.location()).toContain('selected=atlas'));
  });
});
