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

/**
 * The chosen product is remembered in `localStorage`, so it outlives a test.
 *
 * At the file's top level rather than inside one `describe`, since
 * 2026-09-24: it used to sit in the first block only, and the second block
 * passed because nothing had leaked into it *yet*. When the landing stopped
 * navigating away from `/`, something did — and a test that passes on the
 * order its neighbours ran in is a test that proves nothing about the
 * screen.
 */
afterEach(() => {
  window.localStorage.clear();
  // And `sessionStorage`, which since 2026-09-24 holds whether this browsing
  // session has already been landed (`landing.ts` step 3). Left behind, the
  // first test to land would be the only one that ever does, and every test
  // after it would pass by not moving.
  window.sessionStorage.clear();
  useSessionStore.setState({ productCode: null, root: '', tenantSlug: null });
  vi.restoreAllMocks();
});

function clientFor(pending: { tenant: string; name: string }[]) {
  return stubClient({
    'GET /api/v1/products': { data: { products: [], default: null, pending_memberships: pending } },
    'GET /api/v1/staff/me': NOBODY_ON_STAFF,
  });
}

describe('the landing address, signed in', () => {
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

  it('moves them on to the first screen their own menu offers', async () => {
    // Removed earlier on 2026-09-24 so that `/` — the product's story,
    // built that morning — would be an address somebody could reach at all,
    // and restored the same day. Being reachable and being where signing in
    // puts you are different questions: the operator signed in and got a
    // shop window for a product they had already bought, with their
    // projects two clicks away.
    //
    // This member holds `projects.read`, so Work leads their rail.
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', initial: '/?product=atlas' },
    );

    await waitFor(() => expect(location()).toBe('/projects'));
  });

  it('lands once a session, so a later arrival at the story stays on it', async () => {
    // The half the landing must not take away. `/` is in no menu, so the way
    // back is the About link in region A — and that is a real navigation,
    // as is typing the address. Both are fresh mounts, so "have we landed"
    // has to outlive one: it is in `sessionStorage`, not in a ref.
    const stubbed = stubs({ products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] });

    const landing = renderAtRoute(<AppShell />, stubbed, { path: '/', initial: '/?product=atlas' });

    await waitFor(() => expect(landing.location()).toBe('/projects'));
    landing.unmount();

    // Arriving at `/` again, in the same browsing session.
    const again = renderAtRoute(<AppShell />, stubbed, { path: '/', initial: '/?product=atlas' });

    await waitFor(() => expect(screen.getByTestId('context-organisation')).toBeTruthy());
    expect(again.location()).toMatch(/^\/(\?|$)/);
  });

  it('waits for the menu rather than guessing where to send them', async () => {
    // `/me` never answers, so no permission is known and no entry is
    // visible. Moving then would send everybody to whichever screen
    // survives an empty permission set — so nothing moves, and the story is
    // what they are looking at meanwhile.
    const client = stubClient({
      'GET /api/v1/me': { status: 503, error: { error: { code: 'UNAVAILABLE', message: 'later' } } },
      'GET /api/v1/me/navigation': { data: { hidden: [] } },
      'GET /api/v1/products': {
        data: { products: [ATLAS], default: 'atlas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }], pending_memberships: [] },
      },
      'GET /api/v1/public/tenant': { data: { tenant: HQ } },
      'GET /api/v1/staff/me': NOBODY_ON_STAFF,
    });

    const { location } = renderAtRoute(<AppShell />, client, { path: '/', initial: '/?product=atlas' });

    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('atlas'));
    expect(location()).toMatch(/^\/(\?|$)/);
  });

  it('opens in the person\'s own product, unless the address names one', async () => {
    // Remembered from last time: Atlas. Theirs, on the profile: Boreas.
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, BOREAS], default: 'boreas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', product: 'atlas' },
    );

    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('boreas'));
    await waitFor(() => expect(location()).toBe('/projects'));
  });

  /**
   * The organisation's answer, for somebody who has not given their own
   * (2026-09-26).
   *
   * Below the person's and above the bundle's constant, which was the only
   * answer there was: a deployment that leads with Plan opened on Atlas until
   * somebody rebuilt it, and an administrator could do nothing about it.
   */
  it('opens in the organisation\'s product when the person has chosen none', async () => {
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({
        products: [ATLAS, BOREAS],
        default: null,
        memberships: [{ tenant: 'acme', name: 'Acme Ltd', default_product: 'boreas' }],
      }),
      { path: '/' },
    );

    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('boreas'));
    await waitFor(() => expect(location()).toBe('/projects'));
  });

  it('never lets the organisation\'s answer override the person\'s own', async () => {
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({
        products: [ATLAS, BOREAS],
        default: 'atlas',
        memberships: [{ tenant: 'acme', name: 'Acme Ltd', default_product: 'boreas' }],
      }),
      { path: '/' },
    );

    await waitFor(() => expect(location()).toBe('/projects'));
    // Theirs, not the organisation's: an administrator answers for whoever
    // has not answered, and never over one who has.
    expect(useSessionStore.getState().productCode).toBe('atlas');
  });

  it('ignores an organisation\'s answer naming a product this person does not hold', async () => {
    const { location } = renderAtRoute(
      <AppShell />,
      stubs({
        products: [ATLAS],
        default: null,
        // Boreas was unassigned some other way, or this person lost it: a
        // stale default is ignored, never obeyed.
        memberships: [{ tenant: 'acme', name: 'Acme Ltd', default_product: 'boreas' }],
      }),
      { path: '/', product: 'atlas' },
    );

    await waitFor(() => expect(location()).toBe('/projects'));
    expect(useSessionStore.getState().productCode).toBe('atlas');
  });

  it('keeps the product the address names over the person\'s own', async () => {
    // The address is what the browser holds, not the router's memory.
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, search: '?product=atlas' });

    const { location } = renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, BOREAS], default: 'boreas', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', initial: '/?product=atlas' },
    );

    await waitFor(() => expect(screen.getByTestId('context-organisation')).toBeTruthy());
    expect(useSessionStore.getState().productCode).toBe('atlas');
    await waitFor(() => expect(location()).toBe('/projects'));
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

  it('keeps a person whose product lives beside the platform on the platform', async () => {
    // The landing used to leave for the product's own address (ADR-051 §3,
    // amended 2026-09-24). Somebody whose default product lives elsewhere
    // could then never reach a platform screen for it: `/` threw them out
    // before the shell rendered, and their subscription, invoices, members
    // and projects are all here. Going to the product is a door they open.
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    const PLAN = { id: 'prod-plan', code: 'plan', name: 'Plan', app_url: 'https://plan.example.test' };

    renderAtRoute(
      <AppShell />,
      stubs({ products: [ATLAS, PLAN], default: 'plan', memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] }),
      { path: '/', product: 'plan' },
    );

    // The shell renders — region A names the organisation — and nothing
    // navigates away from it.
    await waitFor(() => expect(screen.getByTestId('context-organisation')).toBeTruthy());
    expect(assign.mock.calls.filter((call) => String(call[0]).startsWith('https://'))).toEqual([]);
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

    await waitFor(() => expect(screen.getByTestId('context-organisation')).toBeTruthy());
    expect(assign).not.toHaveBeenCalled();
    expect(location()).toBe('/');
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
