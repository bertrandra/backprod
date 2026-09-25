import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient, type Stubs } from '@/test-utils';

import { OrganisationSubscriptionsScreen } from './OrganisationSubscriptionsScreen';

/**
 * Who holds what, and how many places are taken (2026-09-25).
 *
 * The fixture is built to catch a screen that derives rather than reads:
 * `live` disagrees with what a date would suggest on one row, and the two
 * kinds of `places_sold` null are both present — an unlimited offer, and an
 * offer that sells no places at all, which the server sends as 1.
 */
const ADMIN = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'tenant.manage', 'subscription.read', 'subscription.manage'],
};

// A member, with both permissions that look like they might do and neither
// that does. `subscription.manage` is deliberately here: a USER holds it —
// they manage the people on their own seat — so a screen gated on it would
// hand this person the whole organisation's register.
const MEMBER = {
  ...SESSION,
  roles: ['USER'],
  permissions: ['subscription.read', 'subscription.manage'],
};

function held(overrides: Record<string, unknown> = {}) {
  return {
    id: 's-1',
    status: 'ACTIVE',
    subscriber_kind: 'USER',
    live: true,
    holder: { user_id: 'u-2', name: 'Bo', email: 'bo@acme.test' },
    offer_name: 'Pro',
    plan_name: 'Pro',
    billing_period: 'MONTHLY',
    price: { minor_units: 2900, currency: 'EUR' },
    current_period_end: '2026-10-25T10:00:00Z',
    places_sold: 3,
    places_used: 2,
    ...overrides,
  };
}

function clientFor(subscriptions: unknown[], session: Record<string, unknown> = ADMIN) {
  return stubClient(stubsFor(subscriptions, session));
}

function stubsFor(subscriptions: unknown[], session: Record<string, unknown> = ADMIN): Stubs {
  return {
    'GET /api/v1/me': { data: session },
    'GET /api/v1/organisation/subscriptions': { data: { subscriptions } },
  };
}

describe('who holds what', () => {
  it('names the holder, the offer and the places taken', async () => {
    renderWith(<OrganisationSubscriptionsScreen />, clientFor([held()]));

    await waitFor(() => expect(screen.getByText('Bo')).toBeTruthy());
    expect(screen.getByText('bo@acme.test')).toBeTruthy();
    expect(screen.getAllByText('Pro').length).toBeGreaterThan(0);
    // Two of three, the holder counted among them — the server's number,
    // rendered, never a length computed here.
    expect(screen.getByTestId('places').textContent).toContain('2');
    expect(screen.getByTestId('places').textContent).toContain('3');
    expect(screen.getByTestId('places').getAttribute('data-full')).toBe('false');
  });

  it('says a full subscription is full', async () => {
    renderWith(<OrganisationSubscriptionsScreen />, clientFor([held({ places_used: 3, places_sold: 3 })]));

    await waitFor(() => expect(screen.getByTestId('places')).toBeTruthy());
    expect(screen.getByTestId('places').getAttribute('data-full')).toBe('true');
  });

  it('tells an unlimited offer from one that sells no places at all', async () => {
    // Both are a null limit in the database and the server resolves them
    // before they arrive: unlimited is null, and "no grant" is 1. A screen
    // that read the column itself would call them the same thing.
    renderWith(
      <OrganisationSubscriptionsScreen />,
      clientFor([
        held({ id: 's-scale', offer_name: 'Scale', places_sold: null, places_used: 4 }),
        held({ id: 's-solo', offer_name: 'Solo', places_sold: 1, places_used: 1 }),
      ]),
    );

    await waitFor(() => expect(screen.getAllByTestId('places').length).toBe(2));

    const unlimited = document.querySelector('[data-subscription="s-scale"] [data-testid="places"]');
    const solo = document.querySelector('[data-subscription="s-solo"] [data-testid="places"]');

    expect(unlimited?.textContent).toContain('∞');
    expect(unlimited?.getAttribute('data-full')).toBe('false');
    expect(solo?.textContent).toContain('1 / 1');
    expect(solo?.getAttribute('data-full')).toBe('true');
  });

  it('reads `live` from the server rather than from the date', async () => {
    // A period end in the future and `live: false` — a combination no clock
    // would produce, and exactly what a screen deriving the state would get
    // wrong. The server decides; this renders.
    renderWith(
      <OrganisationSubscriptionsScreen />,
      clientFor([held({ status: 'CANCELLED', live: false, current_period_end: '2099-01-01T00:00:00Z' })]),
    );

    await waitFor(() => expect(screen.getByTestId('state')).toBeTruthy());
    expect(screen.getByTestId('state').textContent).toBe('cancelled');
    expect(document.querySelector('[data-subscription="s-1"]')?.getAttribute('data-live')).toBe('false');
  });

  it('puts the living first without re-sorting within them', async () => {
    renderWith(
      <OrganisationSubscriptionsScreen />,
      clientFor([
        held({ id: 's-dead', live: false, status: 'EXPIRED' }),
        held({ id: 's-first' }),
        held({ id: 's-second' }),
      ]),
    );

    await waitFor(() => expect(document.querySelectorAll('[data-subscription]').length).toBe(3));

    expect(
      Array.from(document.querySelectorAll('[data-subscription]')).map((row) =>
        row.getAttribute('data-subscription'),
      ),
    ).toEqual(['s-first', 's-second', 's-dead']);
  });

  it('says nobody rather than attributing a subscription with no holder', async () => {
    renderWith(<OrganisationSubscriptionsScreen />, clientFor([held({ holder: null })]));

    await waitFor(() => expect(screen.getByTestId('no-holder')).toBeTruthy());
    expect(screen.queryByText('Bo')).toBeNull();
  });

  it('explains an empty organisation instead of showing an empty table', async () => {
    renderWith(<OrganisationSubscriptionsScreen />, clientFor([]));

    await waitFor(() => expect(screen.getByText(/nobody here holds anything/i)).toBeTruthy());
    expect(document.querySelector('table')).toBeNull();
  });
});

describe('whose screen it is', () => {
  it('never asks for what a member may not read', async () => {
    const { client, requests } = recordingClient(stubsFor([held()], MEMBER));
    renderWith(<OrganisationSubscriptionsScreen />, client);

    await waitFor(() => expect(screen.getByText(/administrator's view/i)).toBeTruthy());
    // Not a 403 caught and hidden: the request is never made. `subscription.read`
    // is every member's, and this list is not what it reads.
    expect(requests.some((request) => request.path === '/api/v1/organisation/subscriptions')).toBe(false);
  });

  it('points a member at the screen that does answer for them', async () => {
    renderWith(<OrganisationSubscriptionsScreen />, clientFor([held()], MEMBER));

    await waitFor(() => expect(screen.getByText(/administrator's view/i)).toBeTruthy());
    expect(screen.getByText(/Subscription screen/i)).toBeTruthy();
  });
});
