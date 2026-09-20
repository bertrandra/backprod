import { screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
    useSessionStore.setState({ productCode: null, root: '', tenantSlug: null });
    vi.restoreAllMocks();
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

  const ATLAS = { id: 'p-1', code: 'atlas', name: 'Atlas' };
  const BOREAS = { id: 'p-2', code: 'boreas', name: 'Boreas' };

  // The bare host is Acme's — the default organisation.
  const HQ = { slug: 'acme', name: 'Acme Ltd', is_default: true, join_policy: 'OPEN', after_sign_up: 'PAY' };

  function stubs(
    products: { products: unknown[]; default: string | null; memberships: unknown[] },
    tenant: unknown = HQ,
  ) {
    return stubClient({
      'GET /api/v1/me': { data: MEMBER },
      'GET /api/v1/me/navigation': { data: { hidden: [] } },
      'GET /api/v1/products': { data: { ...products, pending_memberships: [] } },
      'GET /api/v1/public/tenant': { data: { tenant } },
      'GET /api/v1/staff/me': NOBODY_ON_STAFF,
    });
  }

  it('goes to the first screen in the menu, and keeps the product in the address', async () => {
    // Whether they came in by the storefront's link or by a deep link's
    // form, signing in lands on what the rail leads with (2026-09-18).
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', initial: '/?product=atlas' },
    );

    await waitFor(() => expect(location()).toMatch(/^\/projects/));
    expect(location()).toContain('product=atlas');
  });

  it('opens in the person\'s own product, unless the address names one', async () => {
    // Remembered from last time: Atlas. Theirs, on the profile: Boreas.
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, BOREAS], default: 'boreas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', product: 'atlas' },
    );

    await waitFor(() => expect(location()).toMatch(/^\/projects/));
    expect(useSessionStore.getState().productCode).toBe('boreas');
  });

  it('keeps the product the address names over the person\'s own', async () => {
    // The address is what the browser holds, not the router's memory.
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, search: '?product=atlas' });

    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, BOREAS], default: 'boreas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', initial: '/?product=atlas' },
    );

    await waitFor(() => expect(location()).toMatch(/^\/projects/));
    expect(useSessionStore.getState().productCode).toBe('atlas');
  });

  it('moves a member of another organisation from the bare host to their own root', async () => {
    // Signed in at the bare host — Acme's — but a member of Zenith: they
    // belong at /zenith/, and the router's basepath is fixed at boot, so it
    // is a full navigation.
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'zenith', name: 'Zenith' }] }),
      { path: '/' },
    );

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/zenith/'));
  });

  it('moves a member of the default organisation back to the bare host from another root', async () => {
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    useSessionStore.getState().enterRoot('/zenith', 'zenith');

    renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/' },
    );

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
  });

  it('sends a person whose product lives beside the platform to its address', async () => {
    // ADR-051 §3: the landing for an external product is the product.
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    const PLAN = { id: 'prod-plan', code: 'plan', name: 'Plan', app_url: 'https://plan.example.test' };

    renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, PLAN], default: 'plan', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', product: 'plan' },
    );

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://plan.example.test/?product=plan&lang=en'));
  });

  it('stays where the root is already theirs', async () => {
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    useSessionStore.getState().enterRoot('/zenith', 'zenith');

    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'zenith', name: 'Zenith' }] }),
      { path: '/' },
    );

    await waitFor(() => expect(location()).toMatch(/^\/projects/));
    expect(assign).not.toHaveBeenCalled();
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
