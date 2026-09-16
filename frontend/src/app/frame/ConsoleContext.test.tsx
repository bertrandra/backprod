import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useConsoleStore } from '@/state/console';
import { renderAtRoute, stubClient } from '@/test-utils';

import { ConsoleContext, tenantIdIn } from './ConsoleContext';

/**
 * The console bar says which level a screen answers to, and offers what
 * narrows it: a customer, then one of the products that customer holds.
 */
const ATLAS = { id: 'p-atlas', code: 'atlas', name: 'Atlas', active: true };
const BOREAS = { id: 'p-boreas', code: 'boreas', name: 'Boreas', active: true };
const ACME = { id: 't-1', name: 'Acme Ltd', slug: 'acme', may_author_offers: false, products: [ATLAS, BOREAS] };
const GLOBEX = { id: 't-2', name: 'Globex', slug: 'globex', may_author_offers: false, products: [ATLAS] };

const client = () =>
  stubClient({
    'GET /api/v1/staff/tenants': { data: { tenants: [ACME, GLOBEX], total: 2, limit: 100, offset: 0 } },
    'GET /api/v1/staff/tenants/{tenantId}': { data: { tenant: ACME } },
  });

beforeEach(() => {
  useConsoleStore.setState({ motives: {}, productCode: null });
});

describe('the level', () => {
  it('is the platform on a platform screen, with no product picker', async () => {
    renderAtRoute(<ConsoleContext />, client(), { path: '/console/products' });

    await waitFor(() => expect(screen.getByRole('option', { name: 'Acme Ltd' })).toBeTruthy());
    expect(screen.getByTestId('console-level').getAttribute('data-level')).toBe('platform');
    expect(screen.queryByTestId('console-product')).toBeNull();
  });

  it('is the tenant inside a customer, named, with its products to narrow to', async () => {
    renderAtRoute(<ConsoleContext />, client(), { path: '/console/tenants/$tenantId', initial: '/console/tenants/t-1' });

    await waitFor(() => expect(screen.getByTestId<HTMLSelectElement>('console-tenant').value).toBe('t-1'));
    expect(screen.getByTestId('console-level').getAttribute('data-level')).toBe('tenant');
    // Only what Acme holds — never the whole platform's catalogue.
    await waitFor(() => expect(screen.getByRole('option', { name: 'Boreas' })).toBeTruthy());
    const options = Array.from(screen.getByTestId('console-product').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['Every product it holds', 'Atlas', 'Boreas']);
  });
});

describe('the pickers', () => {
  it('opens the chosen customer, and platform goes back to the list', async () => {
    const view = renderAtRoute(<ConsoleContext />, client(), { path: '/console/products' });
    await waitFor(() => expect(screen.getByRole('option', { name: 'Globex' })).toBeTruthy());

    fireEvent.change(screen.getByTestId('console-tenant'), { target: { value: 't-2' } });
    await waitFor(() => expect(view.location()).toContain('/console/tenants/t-2'));
  });

  it('narrows the view to one held product, in client state', async () => {
    renderAtRoute(<ConsoleContext />, client(), { path: '/console/tenants/$tenantId', initial: '/console/tenants/t-1' });
    await waitFor(() => expect(screen.getByRole('option', { name: 'Boreas' })).toBeTruthy());

    fireEvent.change(screen.getByTestId('console-product'), { target: { value: 'boreas' } });
    expect(useConsoleStore.getState().productCode).toBe('boreas');

    fireEvent.change(screen.getByTestId('console-product'), { target: { value: '' } });
    expect(useConsoleStore.getState().productCode).toBeNull();
  });
});

describe('tenantIdIn', () => {
  it('reads the customer out of a console address, and nothing else', () => {
    expect(tenantIdIn('/console/tenants/t-1')).toBe('t-1');
    expect(tenantIdIn('/console/tenants/t-1?tab=members')).toBe('t-1');
    expect(tenantIdIn('/console/tenants')).toBeNull();
    expect(tenantIdIn('/console/products')).toBeNull();
    expect(tenantIdIn('/tenants/t-1')).toBeNull();
  });
});
