import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient, type Stub, type Stubs } from '@/test-utils';

import { SubscriptionScreen } from './SubscriptionScreen';

/**
 * Non-negotiable #23: **periodicity is not commitment.**
 *
 * The fixture makes them disagree on purpose — billed monthly, committed for
 * twelve months — because a screen that conflated them would show one number and
 * be wrong about the other. Both are asserted separately.
 *
 * And §13.1: a cancellation is a **decision**, not a boolean. What a customer
 * needs is when it takes effect, what it costs, and which rule said so.
 */
// The administrator: the organisation's subscription is theirs to change
// and cancel (`billing.manage`, the organisation's view — 2026-09-18).
const SUBSCRIBER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'subscription.read', 'subscription.manage', 'billing.manage', 'entitlements.read'],
};

// A member: may act on their own seat and on nothing that binds the organisation.
const MEMBER = {
  ...SESSION,
  roles: ['USER'],
  permissions: ['subscription.read', 'subscription.manage', 'entitlements.read'],
};

const DECISION = {
  accepted: true,
  rule_id: 'cancel.at_commitment_end',
  effect: 'AT_COMMITMENT_END',
  effective_at: '2026-12-31T23:59:59Z',
  chargeable_months: 9,
  reasons: ['A twelve-month commitment was agreed and nine months remain.'],
};

function subscription(overrides: Record<string, unknown> = {}) {
  return {
    id: 'sub-1',
    status: 'ACTIVE',
    offer: {
      id: 'off-1',
      code: 'pro-monthly',
      name: 'Pro monthly',
      plan: { id: 'p-1', code: 'PRO', name: 'Pro', rank: 20 },
      version: {
        id: 'ver-1',
        version: 1,
        // Billed every month…
        billing_period: 'MONTHLY',
        price: { minor_units: 2900, currency: 'EUR' },
        valid_from: '2026-01-01T00:00:00Z',
        valid_until: null,
        grants: [],
      },
    },
    started_at: '2026-01-01T00:00:00Z',
    current_period_start: '2026-03-01T00:00:00Z',
    current_period_end: '2026-04-01T00:00:00Z',
    cancel_at_period_end: false,
    cancel_effective_at: null,
    cancelled_at: null,
    subscriber: {},
    // …and committed for twelve.
    terms: { commitment_months: 12, notice_days: 30 },
    ended_at: null,
    ...overrides,
  };
}

function stubsFor(
  extra: Record<string, Stub | (() => Stub)> = {},
  sub: unknown = subscription(),
  options: { seat?: unknown; session?: unknown } = {},
): Stubs {
  return {
    'GET /api/v1/me': { data: options.session ?? SUBSCRIBER },
    'GET /api/v1/subscription': { data: { subscription: sub, seat: options.seat ?? null, history: [], events: [] } },
    'GET /api/v1/subscription/schedule': {
      data: { subscription: sub, if_cancelled_now: DECISION },
    },
    'GET /api/v1/entitlements': { data: { entitlements: [] } },
    'GET /api/v1/offers': { data: { offers: [] } },
    ...extra,
  };
}

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}, sub: unknown = subscription()) {
  return stubClient(stubsFor(extra, sub));
}

describe('periodicity and commitment', () => {
  it('are shown as two different things', async () => {
    renderWith(<SubscriptionScreen />, clientFor());

    await waitFor(() => expect(screen.getByTestId('periodicity')).toBeTruthy());

    // Monthly billing…
    expect(screen.getByTestId('periodicity').textContent).toContain('monthly');
    // …and a twelve-month commitment. Neither number is the other.
    expect(screen.getByTestId('commitment').textContent).toContain('12 month');
    expect(screen.getByTestId('commitment').textContent).toContain('30 days notice');
  });

  it('says a commitment is unrecorded rather than calling it zero', async () => {
    // Not recorded and none agreed are different answers.
    renderWith(<SubscriptionScreen />, clientFor({}, subscription({ terms: {} })));

    await waitFor(() => expect(screen.getByTestId('commitment')).toBeTruthy());
    expect(screen.getByTestId('commitment').textContent).toMatch(/not recorded/i);
  });
});

describe('cancelling', () => {
  it('previews the decision before the button, with the rule that decided', async () => {
    renderWith(<SubscriptionScreen />, clientFor());

    const decision = await waitFor(() => screen.getByTestId('cancellation-decision'));

    // The rule id is the thing to quote in a support conversation.
    expect(decision.getAttribute('data-rule')).toBe('cancel.at_commitment_end');
    expect(screen.getByTestId('chargeable-months').textContent).toContain('9 months');
    expect(screen.getByText(/nine months remain/i)).toBeTruthy();
  });

  it('says asking to end immediately does not make it so', async () => {
    renderWith(<SubscriptionScreen />, clientFor());

    await waitFor(() => expect(screen.getByLabelText(/end immediately/i)).toBeTruthy());
    expect(screen.getByText(/the cancellation policy decides/i)).toBeTruthy();
  });

  it('is confirmed, and shows what the policy decided afterwards', async () => {
    let cancelled = 0;

    renderWith(
      <SubscriptionScreen />,
      clientFor({
        'POST /api/v1/subscription/cancel': (): Stub => {
          cancelled += 1;

          return {
            data: {
              ...subscription({ cancel_at_period_end: true }),
              cancellation: { ...DECISION, effect: 'AT_PERIOD_END', chargeable_months: 0 },
            },
          };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /cancel…/i })).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /cancel…/i }));
    expect(cancelled).toBe(0);

    fireEvent.click(screen.getByRole('button', { name: /cancel the subscription/i }));
    await waitFor(() => expect(cancelled).toBe(1));

    // The decision that came back, not the one that was asked for.
    await waitFor(() => {
      const decisions = screen.getAllByTestId('cancellation-decision');
      expect(decisions.some((d) => d.getAttribute('data-effect') === 'AT_PERIOD_END')).toBe(true);
    });
  });

  it('offers resuming instead once it is already cancelling', async () => {
    renderWith(
      <SubscriptionScreen />,
      clientFor({}, subscription({ cancel_at_period_end: true, cancel_effective_at: '2026-04-01T00:00:00Z' })),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /resume it/i })).toBeTruthy());
    expect(screen.getByTestId('cancelling')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /cancel…/i })).toBeNull();
  });
});

describe('entitlements', () => {
  it('distinguishes an unlimited quota from a missing limit', async () => {
    // `limit` null means two different things and `unlimited` says which.
    renderWith(
      <SubscriptionScreen />,
      clientFor({
        'GET /api/v1/entitlements': {
          data: {
            entitlements: [
              { feature: 'projects', name: 'Projects', kind: 'QUOTA', unit: 'projects', limit: null, unlimited: true, source: 'grant', valid_until: null },
              { feature: 'seats', name: 'Seats', kind: 'QUOTA', unit: 'seats', limit: 5, unlimited: false, source: 'grant', valid_until: null },
              { feature: 'gis', name: 'GIS', kind: 'BOOLEAN', unit: null, limit: null, unlimited: false, source: 'override', valid_until: null },
            ],
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('unlimited')).toBeTruthy());
    expect(screen.getByText('5 seats')).toBeTruthy();
    expect(screen.getByText('included')).toBeTruthy();
  });
});

describe('with no subscription', () => {
  it('says so without looking broken', async () => {
    renderWith(<SubscriptionScreen />, clientFor({}, null));

    await waitFor(() => expect(screen.getByText(/no subscription/i)).toBeTruthy());
  });
});

describe('a seat of one\'s own (§13.1)', () => {
  const seat = () => subscription({ id: 'seat-1', subscriber: { kind: 'USER', user_id: 'u-1' } });

  it('is shown to its holder beside the organisation\'s subscription, and given up with the flag', async () => {
    const { client, requests } = recordingClient(
      stubsFor(
        {
          'POST /api/v1/subscription/cancel': {
            data: { ...seat(), cancel_at_period_end: true, cancellation: { ...DECISION, effect: 'AT_PERIOD_END', chargeable_months: 0 } },
          },
        },
        subscription(),
        { seat: seat(), session: MEMBER },
      ),
    );

    renderWith(<SubscriptionScreen />, client);

    await waitFor(() => expect(screen.getByTestId('your-seat')).toBeTruthy());
    expect(screen.getByTestId('your-seat').textContent).toContain('Pro monthly');
    // The organisation's is still shown — it is what entitles everyone —
    // and none of its controls are: those are the administrator's.
    expect(screen.getByTestId('periodicity')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /cancel…/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /^change$/i })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /give up your seat…/i }));
    fireEvent.click(screen.getByTestId('cancel-seat'));

    // A flag, never an id: whose seat it is, the server already knows.
    await waitFor(() =>
      expect(requests.filter((request) => request.path === '/api/v1/subscription/cancel').map((request) => request.body)).toEqual([
        { seat: true },
      ]),
    );
  });

  it('stands alone when the organisation has none', async () => {
    renderWith(<SubscriptionScreen />, stubClient(stubsFor({}, null, { seat: seat(), session: MEMBER })));

    await waitFor(() => expect(screen.getByTestId('your-seat')).toBeTruthy());
    expect(screen.getByText(/no subscription for the organisation/i)).toBeTruthy();
  });

  it('is not shown to someone who holds none', async () => {
    renderWith(<SubscriptionScreen />, clientFor());

    await waitFor(() => expect(screen.getByTestId('periodicity')).toBeTruthy());
    expect(screen.queryByTestId('your-seat')).toBeNull();
  });
});

describe('someone who may only read', () => {
  it('sees the state and none of the actions', async () => {
    renderWith(
      <SubscriptionScreen />,
      stubClient({
        'GET /api/v1/me': { data: { ...SESSION, permissions: ['subscription.read'] } },
        'GET /api/v1/subscription': {
          data: { subscription: subscription(), history: [], events: [] },
        },
        'GET /api/v1/subscription/schedule': {
          data: { subscription: subscription(), if_cancelled_now: DECISION },
        },
        'GET /api/v1/entitlements': { data: { entitlements: [] } },
        'GET /api/v1/offers': { data: { offers: [] } },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('periodicity')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /cancel…/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /^change$/i })).toBeNull();
  });
});
