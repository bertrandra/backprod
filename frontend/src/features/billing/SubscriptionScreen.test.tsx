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

/**
 * What the server says a move up would do (spec §3, §7).
 *
 * **The net deliberately does not follow from the other two.** 11 880 less
 * 2 320 is 9 560, and this fixture says 9 000 — because a screen that worked
 * the net out for itself would produce 9 560 and a fixture where the two
 * agreed would not notice. The net is the *server's* answer, because "never add
 * two amounts in the frontend; every total on screen is the server's" (§4,
 * §25), and this is how that is proved rather than trusted.
 */
const CHANGE_UP = {
  accepted: true,
  rule_id: 'change.prorated_now',
  direction: 'UPGRADE',
  effect: 'IMMEDIATE',
  effective_at: '2026-03-11T00:00:00Z',
  currency: 'EUR',
  credit_minor_units: 2320,
  charge_minor_units: 11880,
  net_minor_units: 9000,
  new_period_end: '2026-04-11T00:00:00Z',
  commitment_ends_at: '2026-12-31T23:59:59Z',
  reasons: ['The new plan applies at once, and the billing period restarts today.'],
};

const CHANGE_DOWN = {
  ...CHANGE_UP,
  rule_id: 'change.deferred_to_period_end',
  direction: 'DOWNGRADE',
  effect: 'AT_PERIOD_END',
  effective_at: '2026-04-01T00:00:00Z',
  credit_minor_units: 0,
  charge_minor_units: 0,
  net_minor_units: 0,
  reasons: ['A lower plan takes effect at the end of the period already paid for.'],
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
    // Which organisation this answer is about (2026-09-26). Read rather than
    // assumed, and gated on `tenant.read`.
    'GET /api/v1/tenants/current': { data: { tenant: { id: 't-1', name: 'Acme' } } },
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

/**
 * Spec §4: a move to a **lower** plan changes nothing today.
 *
 * Two things are asserted, and both matter. That the screen sends the
 * *deferring* operation when the chosen plan ranks lower — decided by rank and
 * never by a plan's name, which is §13's rule in the frontend — and that the
 * button undoing it exists, because a future change nobody can undo is a
 * cancellation in disguise (§4.2).
 */
describe('a change of plan that waits (spec §4)', () => {
  const CHEAPER = {
    id: 'off-2',
    code: 'starter-monthly',
    name: 'Starter monthly',
    plan: { id: 'p-0', code: 'STARTER', name: 'Starter', rank: 10 },
    version: null,
  };

  const DEARER = {
    id: 'off-3',
    code: 'scale-monthly',
    name: 'Scale monthly',
    plan: { id: 'p-2', code: 'SCALE', name: 'Scale', rank: 30 },
    version: null,
  };

  const PENDING = {
    offer_id: 'off-2',
    offer_version_id: 'ver-2',
    code: 'starter-monthly',
    name: 'Starter monthly',
    plan: CHEAPER.plan,
    effective_at: '2026-04-01T00:00:00Z',
    requested_at: '2026-03-03T09:00:00Z',
    requested_by: 'u-1',
  };

  it('sends the deferring operation for a lower rank, and the immediate one for a higher', async () => {
    const { client, requests } = recordingClient(
      stubsFor({
        'GET /api/v1/offers': { data: { offers: [CHEAPER, DEARER] } },
        'POST /api/v1/subscription/preview-change': { data: { subscription: subscription(), if_changed_now: CHANGE_DOWN } },
        'POST /api/v1/subscription/pending': { data: subscription({ pending: PENDING }) },
        'POST /api/v1/subscription/change-offer': { data: subscription({ offer: { ...subscription().offer, ...DEARER } }) },
      }),
    );

    renderWith(<SubscriptionScreen />, client);

    await waitFor(() => expect(screen.getByLabelText(/^offer$/i)).toBeTruthy());

    // Rank 10 against the current 20: down, so it waits — and the screen says
    // so before the click rather than after it, from the server's own preview.
    fireEvent.change(screen.getByLabelText(/^offer$/i), { target: { value: 'off-2' } });
    expect(screen.getByTestId('change-offer').getAttribute('data-deferred')).toBe('true');
    await waitFor(() =>
      expect(screen.getByTestId('change-decision').getAttribute('data-effect')).toBe('AT_PERIOD_END'),
    );
    expect(screen.getByTestId('change-effect').textContent).toMatch(/end of the period you have paid for/i);

    fireEvent.click(screen.getByTestId('change-offer'));

    await waitFor(() =>
      expect(requests.filter((request) => request.path === '/api/v1/subscription/pending')).toHaveLength(1),
    );
    expect(requests.filter((request) => request.path === '/api/v1/subscription/change-offer')).toHaveLength(0);

    // Rank 30: up, and up is immediate.
    fireEvent.change(screen.getByLabelText(/^offer$/i), { target: { value: 'off-3' } });
    expect(screen.getByTestId('change-offer').getAttribute('data-deferred')).toBe('false');

    fireEvent.click(screen.getByTestId('change-offer'));

    await waitFor(() =>
      expect(requests.filter((request) => request.path === '/api/v1/subscription/change-offer')).toHaveLength(1),
    );
  });

  /**
   * The preview is the **server's** answer, read and never derived (spec §7).
   *
   * The net the fixture gives is 9 000, and 11 880 − 2 320 is 9 560: a component
   * that subtracted the credit from the charge itself would show the second
   * number, and this assertion is what says it shows the first. Money arithmetic
   * in a screen is a second answer to a question that already has one, and §4
   * forbids it outright.
   *
   * The minor units are asserted rather than the rendered string, because that
   * is the authoritative value and it does not depend on the runtime's locale
   * data.
   */
  it('shows the credit, the new period and the net from the server, and derives none of them', async () => {
    const { client, requests } = recordingClient(
      stubsFor({
        'GET /api/v1/offers': { data: { offers: [CHEAPER, DEARER] } },
        'POST /api/v1/subscription/preview-change': { data: { subscription: subscription(), if_changed_now: CHANGE_UP } },
      }),
    );

    renderWith(<SubscriptionScreen />, client);

    await waitFor(() => expect(screen.getByLabelText(/^offer$/i)).toBeTruthy());
    fireEvent.change(screen.getByLabelText(/^offer$/i), { target: { value: 'off-3' } });

    const decision = await waitFor(() => screen.getByTestId('change-decision'));

    expect(decision.getAttribute('data-rule')).toBe('change.prorated_now');
    expect(decision.getAttribute('data-direction')).toBe('UPGRADE');
    expect(screen.getByTestId('change-credit').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('2320');
    expect(screen.getByTestId('change-charge').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('11880');
    expect(screen.getByTestId('change-net').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('9000');

    // And the offer it asked about is the one that was chosen, in the body —
    // there is no other way to ask, and no hand-written URL anywhere. `seat` is
    // false here because this section acts on the organisation's subscription;
    // it is a flag rather than an id either way (§13.1).
    const asked = requests.filter((request) => request.path === '/api/v1/subscription/preview-change');
    expect(asked).toHaveLength(1);
    expect(asked[0]?.body).toEqual({ offer_id: 'off-3', seat: false });
  });

  /**
   * A move the server cannot price says so rather than showing zeroes. A zero
   * credit and an unpriceable change are different answers, and only one of them
   * means "this costs you nothing".
   */
  it('says a change cannot be priced rather than showing it as free', async () => {
    const client = stubClient(
      stubsFor({
        'GET /api/v1/offers': { data: { offers: [CHEAPER, DEARER] } },
        'POST /api/v1/subscription/preview-change': {
          data: {
            subscription: subscription(),
            if_changed_now: {
              ...CHANGE_UP,
              accepted: false,
              rule_id: 'change.period_not_priceable',
              effect: null,
              effective_at: null,
              credit_minor_units: 0,
              charge_minor_units: 0,
              net_minor_units: 0,
              new_period_end: null,
              reasons: ['These terms have no computable period end.'],
            },
          },
        },
      }),
    );

    renderWith(<SubscriptionScreen />, client);

    await waitFor(() => expect(screen.getByLabelText(/^offer$/i)).toBeTruthy());
    fireEvent.change(screen.getByLabelText(/^offer$/i), { target: { value: 'off-3' } });

    await waitFor(() => expect(screen.getByTestId('change-refused')).toBeTruthy());
    expect(screen.queryByTestId('change-net')).toBeNull();
    expect(screen.getByText(/no computable period end/i)).toBeTruthy();
  });

  it('says which plan it will move to and when, and offers to undo it', async () => {
    const { client, requests } = recordingClient(
      stubsFor(
        { 'DELETE /api/v1/subscription/pending': { data: subscription({ pending: null }) } },
        subscription({ pending: PENDING }),
      ),
    );

    renderWith(<SubscriptionScreen />, client);

    const waiting = await waitFor(() => screen.getByTestId('pending-change'));

    // The plan the server named, and the server's date — never a date this
    // screen worked out from the period.
    expect(waiting.getAttribute('data-plan')).toBe('STARTER');
    expect(waiting.textContent).toContain('Starter');
    expect(waiting.textContent).toMatch(/keep the plan you are on/i);

    fireEvent.click(screen.getByTestId('cancel-pending-change'));

    await waitFor(() =>
      expect(
        requests.filter((request) => request.method === 'DELETE' && request.path === '/api/v1/subscription/pending'),
      ).toHaveLength(1),
    );
  });

  it('shows the waiting change to a member, without the button that undoes it', async () => {
    renderWith(
      <SubscriptionScreen />,
      stubClient(stubsFor({}, subscription({ pending: PENDING }), { session: MEMBER })),
    );

    await waitFor(() => expect(screen.getByTestId('pending-change')).toBeTruthy());
    // Hiding is courtesy; the API is the authority. What a member must not be
    // shown is a control that binds the organisation.
    expect(screen.queryByTestId('cancel-pending-change')).toBeNull();
  });
});

describe('the people a subscription covers (2026-09-19)', () => {
  const PEOPLE = {
    subscription_id: 'sub-1',
    owner_user_id: 'u-1',
    owner: true,
    quota: 3,
    members: [{ user_id: 'u-9', email: 'bo@acme.test', display_name: 'Bo', added_at: '2026-09-19T08:00:00Z' }],
  };

  it('is shown to the owner with the quota, and adds by email, saying an account was made', async () => {
    const { client, requests } = recordingClient(
      stubsFor({
        'GET /api/v1/subscription/people': { data: PEOPLE },
        'GET /api/v1/tenants/current/members': { data: { members: [] } },
        'POST /api/v1/subscription/people': {
          status: 201,
          data: { member: { user_id: 'u-10', email: 'cy@elsewhere.test', display_name: null, added_at: '2026-09-19T09:00:00Z' }, invited: true },
        },
      }),
    );

    renderWith(<SubscriptionScreen />, client);

    await waitFor(() => expect(screen.getByTestId('subscription-people')).toBeTruthy());
    expect(screen.getByTestId('people-count').textContent).toBe('2 of 3 covered');
    expect(screen.getByText('Bo')).toBeTruthy();

    fireEvent.change(screen.getByLabelText(/or anybody, by email/i), { target: { value: 'cy@elsewhere.test' } });
    fireEvent.click(screen.getByTestId('invite-person'));

    await waitFor(() => expect(screen.getByTestId('person-invited')).toBeTruthy());
    expect(requests.find((r) => r.method === 'POST' && r.path === '/api/v1/subscription/people')?.body).toEqual({
      seat: false,
      email: 'cy@elsewhere.test',
    });
    expect(screen.getByTestId('person-invited').textContent).toMatch(/link to choose a password/i);
  });

  it('offers no controls to somebody who is not the owner, and says who decides', async () => {
    renderWith(
      <SubscriptionScreen />,
      stubClient(stubsFor({ 'GET /api/v1/subscription/people': { data: { ...PEOPLE, owner: false, owner_user_id: 'u-2' } } })),
    );

    await waitFor(() => expect(screen.getByTestId('subscription-people')).toBeTruthy());
    expect(screen.queryByTestId('add-person')).toBeNull();
    expect(screen.queryByRole('button', { name: /^remove$/i })).toBeNull();
    expect(screen.getByText(/whoever activated this subscription decides/i)).toBeTruthy();
  });

  it('says when every place is taken', async () => {
    renderWith(
      <SubscriptionScreen />,
      stubClient(stubsFor({ 'GET /api/v1/subscription/people': { data: { ...PEOPLE, quota: 2 } } })),
    );

    await waitFor(() => expect(screen.getByTestId('people-full')).toBeTruthy());
    expect(screen.queryByTestId('add-person')).toBeNull();
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

/**
 * Whose answer this is (2026-09-26).
 *
 * A seat is bought by one person and given up by them alone, so "whose seat is
 * this?" has an answer the screen did not give. And somebody belonging to two
 * organisations is one switcher click from reading the other one's
 * subscription with nothing on the page to say so.
 */
describe('the screen says who is asking and where', () => {
  it('names the person and the organisation', async () => {
    renderWith(<SubscriptionScreen />, clientFor());

    await waitFor(() =>
      expect(screen.getByTestId('subscription-whose').textContent).toContain('Acme'),
    );

    expect(screen.getByTestId('subscription-whose').textContent).toContain('Ada');
  });

  it('says it on the empty screen too, where there is nothing else to go on', async () => {
    renderWith(<SubscriptionScreen />, clientFor({}, null));

    await waitFor(() =>
      expect(screen.getByTestId('subscription-whose').textContent).toContain('Acme'),
    );
  });

  it('leaves the organisation out rather than guessing when the read is refused', async () => {
    renderWith(
      <SubscriptionScreen />,
      stubClient(
        stubsFor({
          'GET /api/v1/tenants/current': {
            status: 403,
            error: { error: { code: 'PERMISSION_DENIED', message: 'no', details: {}, request_id: 'r' } },
          },
        }),
      ),
    );

    await waitFor(() => expect(screen.getByTestId('subscription-whose')).toBeTruthy());

    // The person is the session's and is still said; the organisation is the
    // server's answer and there is none, so nothing stands in for it. A name
    // invented here would be a second answer to a question that has one.
    expect(screen.getByTestId('subscription-whose').textContent).toContain('Ada');
    expect(screen.getByTestId('subscription-whose').textContent).not.toContain('Acme');
  });
});
