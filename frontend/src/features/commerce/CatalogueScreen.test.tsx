import { fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { recordingClient, renderAtRoute, SESSION, stubClient, type Stub, type Stubs } from '@/test-utils';

import { CatalogueScreen } from './CatalogueScreen';

// Stripe's SDK, replaced: what is under test is that the form is offered on
// this screen, not what Stripe does inside it (that is PaymentElementPanel's).
vi.mock('@stripe/stripe-js', () => ({ loadStripe: vi.fn(() => Promise.resolve({ confirmPayment: vi.fn() })) }));
vi.mock('@stripe/react-stripe-js', () => ({
  Elements: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  PaymentElement: () => <div data-testid="stripe-payment-element" />,
  useStripe: () => ({ confirmPayment: vi.fn() }),
  useElements: () => ({}),
}));

/**
 * The read that must never branch on a product or a plan name (§6, §13,
 * non-negotiable #25).
 *
 * The fixture is built to catch a name comparison rather than to look realistic:
 * the plan that ranks *lowest* is called ZEBRA and the highest ALPHA, so anything
 * sorting by name puts them in the opposite order to anything sorting by rank.
 * A screen that reads correctly here is reading `rank`.
 */
// An administrator, holding everything this screen ever gated on — including
// `billing.manage`, `sales.manage` and `tax.read`, which gated the
// organisation's purchase and the quote until 2026-09-25. Kept deliberately:
// a fixture that dropped them could not tell "the button is gone" from "this
// person never had it".
const BUYER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'catalog.read', 'billing.pay', 'billing.manage', 'sales.manage', 'tax.read'],
};

// A member, as self-service leaves them: buys a seat of their own and nothing
// that binds the organisation.
const MEMBER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'catalog.read', 'billing.pay', 'subscription.read'],
};

const SEAT_BUTTON = /^buy for yourself$/i;
const TENANT_BUTTON = /^buy for the organisation$/i;

function subscriptionRead(subscription: unknown, seat: unknown = null, freemiumUsed = false) {
  return {
    'GET /api/v1/subscription': {
      data: { subscription, seat, history: [], events: [], freemium_used: freemiumUsed },
    },
  };
}

const live = (overrides: Record<string, unknown> = {}) => ({
  id: 'sub-1',
  status: 'ACTIVE',
  offer: { id: 'o-1', code: 'zebra-monthly', name: 'Zebra monthly', plan: ZEBRA, version: offer('o-1', ZEBRA).version },
  pending: null,
  ...overrides,
});

// A business, as the tax profile records it: the one fact a quote turns on.
const BUSINESS = {
  tenant_id: 't-1',
  customer_kind: 'B2B',
  country_code: 'FR',
  taxable_person: true,
  location_evidence: {},
  vat_number: 'FR12345678901',
  vat_number_status: 'VERIFIED',
  vat_number_verified_at: '2026-08-01T09:00:00Z',
  vat_number_country: 'FR',
  reverse_charge_available: false,
};

// Three ranks, so a move up, a move down and the bottom of the list all
// exist in one fixture. GAMMA ranks *below* ZEBRA and is named after neither
// end of the alphabet: the point of these names is that nothing sorting or
// comparing by word can pass (§13).
const GAMMA = { id: 'p-gamma', code: 'GAMMA', name: 'Gamma', rank: 5 };
const ZEBRA = { id: 'p-zebra', code: 'ZEBRA', name: 'Zebra', rank: 10 };
const ALPHA = { id: 'p-alpha', code: 'ALPHA', name: 'Alpha', rank: 20 };

const TERMS = {
  term_months: null,
  commitment_months: 0,
  cancellation_policy: 'ANYTIME',
  renewal: 'AUTO_RENEW',
  early_termination: 'FREE',
  notice_days: 0,
};

function offer(id: string, plan: typeof ZEBRA, overrides: Record<string, unknown> = {}) {
  return {
    id,
    code: `${plan.code.toLowerCase()}-monthly`,
    name: `${plan.name} monthly`,
    plan,
    version: {
      id: `v-${id}`,
      version: 1,
      billing_period: 'MONTHLY',
      price: { minor_units: 2900, currency: 'EUR' },
      valid_from: '2026-01-01T00:00:00Z',
      valid_until: null,
      // The server's answer to "is this the free period" (§6), never derived
      // here from the price and a renewal setting.
      freemium: false,
      terms: TERMS,
      grants: [],
    },
    ...overrides,
  };
}

/**
 * The free period, as the contract describes it: `freemium: true`, which the
 * platform derives from the version being free *and* ending at its term.
 *
 * Priced at zero and named after nothing: a fixture whose plan were called
 * FREEMIUM would let a screen comparing codes pass.
 */
function freePeriod(id = 'o-free', plan = GAMMA) {
  return offer(id, plan, {
    version: {
      ...offer(id, plan).version,
      price: { minor_units: 0, currency: 'EUR' },
      freemium: true,
      terms: { ...TERMS, renewal: 'ENDS_AT_TERM' },
    },
  });
}

function stubsFor(
  offers: unknown[],
  extra: Record<string, Stub | (() => Stub)> = {},
  session: Record<string, unknown> = BUYER,
): Stubs {
  return {
    'GET /api/v1/me': { data: session },
    'GET /api/v1/tax/profile': { data: { profile: BUSINESS } },
    'GET /api/v1/offers': { data: { offers } },
    'GET /api/v1/plans': { data: { plans: [ALPHA, ZEBRA, GAMMA] } },
    'GET /api/v1/products/{productId}/catalog': {
      data: {
        product: { id: 'prod-1', code: 'atlas', name: 'Atlas' },
        features: [],
        configuration: {},
      },
    },
    ...extra,
  };
}

function clientFor(
  offers: unknown[],
  extra: Record<string, Stub | (() => Stub)> = {},
  session: Record<string, unknown> = BUYER,
) {
  return stubClient(stubsFor(offers, extra, session));
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<CatalogueScreen />, client, { path: '/catalogue' });

describe('plans', () => {
  it('are ordered by rank, not by name', async () => {
    render(clientFor([offer('o-1', ZEBRA), offer('o-2', ALPHA)]));

    await waitFor(() => expect(document.querySelectorAll('[data-plan]')).toHaveLength(2));

    const order = [...document.querySelectorAll('[data-plan]')].map((section) =>
      section.getAttribute('data-plan'),
    );

    // ZEBRA ranks 10 and ALPHA ranks 20, so Zebra comes first. Alphabetical
    // ordering would give the opposite, which is exactly the defect §13 forbids.
    expect(order).toEqual(['ZEBRA', 'ALPHA']);
  });

  it('show the rank, so the ordering is explicable', async () => {
    render(clientFor([offer('o-1', ZEBRA)]));

    await waitFor(() => expect(screen.getByText(/rank 10/)).toBeTruthy());
  });
});

describe('an offer with no sellable version', () => {
  it('says so rather than rendering a price it does not have', async () => {
    // The contract types `version` as nullable — "null only in principle, but
    // typed honestly rather than asserted away". A zero here would be a lie,
    // because zero is a legitimate price.
    render(clientFor([offer('o-1', ZEBRA, { version: null })]));

    await waitFor(() => expect(screen.getByTestId('no-sellable-version')).toBeTruthy());
    expect(document.querySelector('[data-minor-units]')).toBeNull();
    expect(screen.queryByRole('button', { name: /^buy/i })).toBeNull();
  });
});

describe('what the catalogue offers to do', () => {
  it('offers one purchase, and it is a seat — even to somebody who may do everything', async () => {
    // BUYER holds `billing.manage`, `sales.manage` and `tax.read`, which is
    // exactly what the organisation's purchase and the quote were gated on
    // until 2026-09-25. Neither is offered, because neither exists: the
    // tenant surface sells seats.
    render(clientFor([offer('o-1', ZEBRA)]));

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(screen.queryByRole('button', { name: TENANT_BUTTON })).toBeNull();
    expect(screen.queryByRole('button', { name: /^quote$/i })).toBeNull();
  });

  it('never asks for the fiscal record at all any more', async () => {
    // It was read to decide whether to offer a quote — a document for a B2B
    // customer. With no quote there is nothing for it to decide, and a screen
    // that keeps fetching what it no longer reads is a 403 waiting for the
    // first person who lacks `tax.read`.
    const { client, requests } = recordingClient(stubsFor([offer('o-1', ZEBRA)]));
    render(client);

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(requests.some((request) => request.path === '/api/v1/tax/profile')).toBe(false);
  });

  it('offers a member a seat of their own and nothing that binds the organisation', async () => {
    // Self-service leaves a stranger a USER: `billing.pay` and no
    // `billing.manage`. They buy for themselves; the organisation's
    // subscription is the administrator's.
    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(null), MEMBER));

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(screen.queryByRole('button', { name: TENANT_BUTTON })).toBeNull();
    expect(screen.queryByRole('button', { name: /^quote$/i })).toBeNull();
  });

  it('tells a member nothing about the organisation\'s subscription — it was never theirs to buy', async () => {
    // The notice explains a button this person would otherwise have had.
    // A member never had the organisation's, so the organisation being
    // subscribed is somebody else's business (the operator's report,
    // 2026-09-18); their own seat is still offered.
    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(live()), MEMBER));

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(screen.queryByTestId('already-subscribed')).toBeNull();
    expect(screen.queryByTestId('tenant-subscribed')).toBeNull();
  });

  it('never asks a member for the fiscal record it may not read', async () => {
    const { client, requests } = recordingClient(stubsFor([offer('o-1', ZEBRA)], subscriptionRead(null), MEMBER));
    render(client);

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(requests.some((request) => request.path === '/api/v1/tax/profile')).toBe(false);
  });

  it('still offers a seat while the organisation is subscribed, and says nothing about it', async () => {
    // An organisation subscription can still exist — a deployment may hold one
    // from before 2026-09-25, and the platform can still grant one. It decides
    // nothing here: there is no organisation button for it to withhold, and
    // telling somebody about a purchase they cannot make reads as somebody
    // else's business.
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(live()), subscriber));

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(screen.queryByTestId('already-subscribed')).toBeNull();
    expect(screen.queryByTestId('tenant-subscribed')).toBeNull();
    expect(screen.queryByTestId('current-plan')).toBeNull();
    // Still a catalogue: the prices are what `catalog.read` is for.
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });

  it('withholds the seat while the person already holds one, and says where the button was', async () => {
    // One live seat per person (409 SEAT_ALREADY_ACTIVE), which is now the
    // only refusal this screen has to explain.
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(null, live()), subscriber));

    await waitFor(() => expect(screen.getByTestId('already-seated')).toBeTruthy());
    expect(screen.getByTestId('already-seated').textContent).toContain('Zebra monthly');
    expect(screen.queryByRole('button', { name: SEAT_BUTTON })).toBeNull();
    // Where the button was, the plan they are on — which since 2026-09-27 is
    // more than a note: it is the row that offers to leave it (§7).
    expect(screen.getByTestId('current-plan').textContent).toMatch(/your current plan/i);
    expect(screen.queryByRole('button', { name: TENANT_BUTTON })).toBeNull();
  });

  it('offers the seat once the subscription read says there is none', async () => {
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(null), subscriber));

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    expect(screen.queryByTestId('already-seated')).toBeNull();
  });

  it('offers neither to someone who may only read', async () => {
    // Hiding is courtesy — the API refuses either way — but a catalogue with
    // buttons that always fail is worse than one with prices and no buttons.
    render(
      clientFor([offer('o-1', ZEBRA)], {}, { ...SESSION, permissions: ['catalog.read'] }),
    );

    await waitFor(() => expect(screen.getByText('Zebra monthly')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^buy/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /^quote$/i })).toBeNull();
    // And no explanation for a button they were never offered.
    expect(screen.queryByTestId('tenant-subscribed')).toBeNull();
    expect(screen.queryByTestId('current-plan')).toBeNull();
    // The price is still there: reading the catalogue is the permission they hold.
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });

  const OPENED = {
    id: 'order-1',
    order_id: 'order-1',
    status: 'AWAITING_PAYMENT',
    invoice_id: null,
    subscription_id: null,
    payment_id: null,
    payment_status: null,
    client_secret: 'pi_1_secret_never_stored',
    payment_provider: { name: 'stripe', publishable_key: 'pk_test_1', sandbox: true },
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 580, currency: 'EUR' },
    gross: { minor_units: 3480, currency: 'EUR' },
    description: 'Zebra monthly (v1)',
    seat: false,
  };

  it('offers the card form where the secret was born, and the order is one click away', async () => {
    const { client, requests } = recordingClient(
      stubsFor([offer('o-1', ZEBRA)], {
        'POST /api/v1/checkout/sessions': { data: { session: OPENED }, status: 201 },
      }),
    );
    const { location } = render(client);

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: SEAT_BUTTON }));

    // Not a navigation: the secret is returned once (ADR-034) and the order
    // page cannot hold it, so leaving now would leave an order nobody can pay.
    await waitFor(() => expect(screen.getByTestId('catalogue-pay')).toBeTruthy());
    expect(location()).not.toContain('/checkout/');
    // Exactly this, and a `seat` field would fail it: the contract dropped it
    // on 2026-09-25, so a body still carrying one is a client that thinks it
    // can still buy for the organisation. Whose seat it is, the server knows.
    expect(requests.filter((request) => request.method === 'POST').map((request) => request.body)).toEqual([
      { offer_id: 'o-1' },
    ]);
    expect(screen.getByTestId('catalogue-pay').textContent).toContain('for yourself');
    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
    // The server's amount on the button, never re-derived here.
    expect(screen.getByRole('button', { name: /Pay/ }).querySelector('[data-minor-units="3480"]')).not.toBeNull();

    // The session id is the order id, so the link names the order.
    fireEvent.click(screen.getByTestId('continue-to-order'));
    await waitFor(() => expect(location()).toContain('/checkout/order-1'));
  });

  it('says on the page that the seat is the buyer\'s own', async () => {
    render(
      clientFor([offer('o-1', ZEBRA)], {
        'POST /api/v1/checkout/sessions': { data: { session: { ...OPENED, seat: true } }, status: 201 },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: SEAT_BUTTON }));

    await waitFor(() => expect(screen.getByTestId('catalogue-pay')).toBeTruthy());
    expect(screen.getByTestId('catalogue-pay').getAttribute('data-seat')).toBe('true');
    expect(screen.getByTestId('catalogue-pay').textContent).toContain('for yourself');
  });

  it('still leads to the order when there is nothing to pay', async () => {
    render(
      clientFor([offer('o-1', ZEBRA)], {
        'POST /api/v1/checkout/sessions': {
          data: {
            session: {
              id: 'order-2',
              order_id: 'order-2',
              status: 'COMPLETED',
              invoice_id: null,
              subscription_id: 's-1',
              payment_id: null,
              payment_status: null,
              client_secret: null,
              payment_provider: null,
              net: { minor_units: 0, currency: 'EUR' },
              vat: { minor_units: 0, currency: 'EUR' },
              gross: { minor_units: 0, currency: 'EUR' },
              description: 'Zebra monthly (v1)',
              seat: false,
            },
          },
          status: 201,
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: SEAT_BUTTON }));

    await waitFor(() => expect(screen.getByTestId('continue-to-order')).toBeTruthy());
    expect(screen.queryByTestId('payment-panel')).toBeNull();
  });
});

describe('an empty catalogue', () => {
  it('explains itself instead of looking broken', async () => {
    render(clientFor([]));

    await waitFor(() => expect(screen.getByText(/nothing is on sale/i)).toBeTruthy());
    expect(screen.getByText(/publishing one puts it here/i)).toBeTruthy();
  });
});

/**
 * §7's table, row by row — and the three things it is easiest to get wrong.
 *
 * A **subscriber**: holds `subscription.read` and `subscription.manage`, which
 * is what the change and cancel endpoints require, and `billing.pay`, which is
 * what acquiring one requires. Holding all of them in one fixture is what lets
 * these tests tell "the button is not offered" from "this person could not have
 * had it".
 */
const SUBSCRIBER = {
  ...SESSION,
  permissions: [
    ...SESSION.permissions,
    'catalog.read',
    'billing.pay',
    'subscription.read',
    'subscription.manage',
  ],
};

/**
 * A priced move up, as the server decides it — and the net is **deliberately
 * not** `charge − credit`.
 *
 * 3900 − 900 is 3000, and the net here is 2450. A consistent fixture would let
 * a screen doing its own arithmetic pass, which is the whole defect §4 and §25
 * forbid: the server's calculation includes things a client cannot see (what
 * was actually *collected*, what has already been given back, how rounding
 * fell), so the only figure that can be shown is the one that came back.
 */
const MOVING_UP = {
  accepted: true,
  rule_id: 'change.prorated_now',
  direction: 'UPGRADE',
  effect: 'IMMEDIATE',
  effective_at: '2026-09-27T09:00:00Z',
  currency: 'EUR',
  credit_minor_units: 900,
  charge_minor_units: 3900,
  net_minor_units: 2450,
  new_period_end: '2026-10-27T09:00:00Z',
  commitment_ends_at: null,
  reasons: ['The unconsumed share of the current period is credited.'],
};

const MOVING_DOWN = {
  accepted: true,
  rule_id: 'change.deferred_to_period_end',
  direction: 'DOWNGRADE',
  effect: 'AT_PERIOD_END',
  effective_at: '2026-10-15T09:00:00Z',
  currency: 'EUR',
  credit_minor_units: 0,
  charge_minor_units: 0,
  net_minor_units: 0,
  new_period_end: null,
  commitment_ends_at: null,
  reasons: ['Nothing is charged today.'],
};

const IF_CANCELLED = {
  accepted: true,
  rule_id: 'cancel.deferred_to_commitment_end',
  effect: 'AT_COMMITMENT_END',
  effective_at: '2026-12-31T09:00:00Z',
  chargeable_months: 3,
  reasons: ['The commitment has three months left to run.'],
};

function previewing(decision: unknown) {
  return {
    'POST /api/v1/subscription/preview-change': {
      data: { subscription: live(), if_changed_now: decision },
    },
  };
}

function scheduling(decision: unknown = IF_CANCELLED) {
  return {
    'GET /api/v1/subscription/schedule': {
      data: { subscription: live(), if_cancelled_now: decision },
    },
  };
}

describe('what one offer would do to the seat already held (§7)', () => {
  it('offers the immediate move for a higher rank and the deferred one for a lower, from the rank and nothing else', async () => {
    // ALPHA ranks 20 and GAMMA ranks 5 against a seat on ZEBRA at 10. Read by
    // name, ALPHA sorts *first* and GAMMA before ZEBRA — which is why the
    // fixture is named this way.
    render(
      clientFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA), offer('o-down', GAMMA)],
        { ...subscriptionRead(null, live()), ...scheduling(), ...previewing(MOVING_UP) },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('current-plan')).toBeTruthy());

    expect(document.querySelector('[data-offer="o-1"]')?.getAttribute('data-standing')).toBe('CURRENT');
    expect(document.querySelector('[data-offer="o-up"]')?.getAttribute('data-standing')).toBe('UP');
    expect(document.querySelector('[data-offer="o-down"]')?.getAttribute('data-standing')).toBe('DOWN');

    // One button each, and they are different buttons because they are
    // different operations.
    expect(
      document.querySelector('[data-offer="o-up"]')?.querySelector('[data-testid="move-up"]'),
    ).not.toBeNull();
    expect(
      document.querySelector('[data-offer="o-down"]')?.querySelector('[data-testid="move-down"]'),
    ).not.toBeNull();
    expect(
      document.querySelector('[data-offer="o-up"]')?.querySelector('[data-testid="move-down"]'),
    ).toBeNull();
  });

  it('shows the server’s net and never the difference it could have worked out', async () => {
    render(
      clientFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA)],
        { ...subscriptionRead(null, live()), ...scheduling(), ...previewing(MOVING_UP) },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('what-it-would-do')).toBeTruthy());

    const line = screen.getByTestId('what-it-would-do');

    expect(line.getAttribute('data-effect')).toBe('IMMEDIATE');
    expect(line.getAttribute('data-rule')).toBe('change.prorated_now');
    // The server said 2450. `charge − credit` is 3000, and the fixture makes
    // them differ on purpose: a screen that subtracted would render 3000 here.
    expect(line.querySelector('[data-minor-units="2450"]')).not.toBeNull();
    expect(line.querySelector('[data-minor-units="3000"]')).toBeNull();
    expect(line.textContent).toMatch(/to pay today/i);
  });

  it('says when a lower plan takes effect, on the server’s date', async () => {
    render(
      clientFor(
        [offer('o-1', ZEBRA), offer('o-down', GAMMA)],
        { ...subscriptionRead(null, live()), ...scheduling(), ...previewing(MOVING_DOWN) },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('what-it-would-do')).toBeTruthy());

    const line = screen.getByTestId('what-it-would-do');

    expect(line.getAttribute('data-effect')).toBe('AT_PERIOD_END');
    expect(line.textContent).toMatch(/at the end of the period you have paid for/i);
    // The date the decision carries, never one derived from `current_period_end`.
    expect(line.textContent).toContain(new Date(MOVING_DOWN.effective_at).toLocaleDateString('en'));
  });

  it('sends the deferred operation for a lower plan, and the immediate one for a higher', async () => {
    const { client, requests } = recordingClient(
      stubsFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA), offer('o-down', GAMMA)],
        {
          ...subscriptionRead(null, live()),
          ...scheduling(),
          ...previewing(MOVING_DOWN),
          'POST /api/v1/subscription/pending': { data: live() },
          'POST /api/v1/subscription/change-offer': { data: { subscription: live(), change: MOVING_UP } },
        },
        SUBSCRIBER,
      ),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('move-down')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-down'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/pending')).toBe(true),
    );

    // The one that matters: `change-offer` would take a lower plan **now**,
    // and take away service the customer has paid for.
    expect(requests.some((request) => request.path === '/api/v1/subscription/change-offer')).toBe(false);

    fireEvent.click(screen.getByTestId('move-up'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/change-offer')).toBe(true),
    );
  });

  it('names the caller’s seat on every one of them, and never an id', async () => {
    const { client, requests } = recordingClient(
      stubsFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA), offer('o-down', GAMMA)],
        {
          ...subscriptionRead(null, live()),
          ...scheduling(),
          ...previewing(MOVING_UP),
          'POST /api/v1/subscription/pending': { data: live() },
          'POST /api/v1/subscription/change-offer': { data: { subscription: live(), change: MOVING_UP } },
          'POST /api/v1/subscription/cancel': { data: { subscription: live(), cancellation: IF_CANCELLED } },
        },
        SUBSCRIBER,
      ),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());

    fireEvent.click(screen.getByTestId('move-up'));
    fireEvent.click(screen.getByTestId('move-down'));
    fireEvent.click(screen.getByTestId('confirm-cancel-seat'));
    await waitFor(() => expect(screen.getByTestId('cancel-seat')).toBeTruthy());
    fireEvent.click(screen.getByTestId('cancel-seat'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/cancel')).toBe(true),
    );

    // Every body that names a subscription names the **seat** — a flag, and
    // never an id, because the only two subscribers are the tenant and the
    // caller (§13.1). Without it these reach the organisation's subscription,
    // which the tenant surface no longer sells: nobody holds one.
    const bodies = (path: string) =>
      requests.filter((request) => request.path === path).map((request) => request.body);

    expect(bodies('/api/v1/subscription/change-offer')).toEqual([{ offer_id: 'o-up', seat: true }]);
    expect(bodies('/api/v1/subscription/pending')).toEqual([{ offer_id: 'o-down', seat: true }]);
    expect(bodies('/api/v1/subscription/cancel')).toEqual([{ seat: true }]);
    // And the previews too — a preview of the organisation's subscription
    // would be a preview of something the customer does not have.
    for (const body of bodies('/api/v1/subscription/preview-change')) {
      expect(body).toMatchObject({ seat: true });
    }

    // The two reads that take the flag in the query, a GET and a DELETE having
    // no body to put it in.
    expect(
      requests
        .filter((request) => request.path === '/api/v1/subscription/schedule')
        .map((request) => request.query),
    ).toContainEqual({ seat: '1' });
  });

  it('offers to leave the plan it is on, with the decision the server would take', async () => {
    render(
      clientFor(
        [offer('o-1', ZEBRA)],
        { ...subscriptionRead(null, live()), ...scheduling() },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('cancellation-decision')).toBeTruthy());

    const decision = screen.getByTestId('cancellation-decision');

    // The rule and the months owed, not `cancelled: true` (non-negotiable #23).
    expect(decision.getAttribute('data-rule')).toBe('cancel.deferred_to_commitment_end');
    expect(screen.getByTestId('chargeable-months').textContent).toMatch(/3 months of commitment/i);
    // And the button, behind a confirmation.
    fireEvent.click(screen.getByTestId('confirm-cancel-seat'));
    await waitFor(() => expect(screen.getByTestId('cancel-seat')).toBeTruthy());
  });

  it('shows a scheduled change where it was asked for, and offers to withdraw it', async () => {
    const pending = {
      offer_id: 'o-down',
      offer_version_id: 'v-o-down',
      code: 'gamma-monthly',
      name: 'Gamma monthly',
      plan: GAMMA,
      effective_at: '2026-10-15T09:00:00Z',
      requested_at: '2026-09-27T09:00:00Z',
      requested_by: 'u-1',
    };

    const { client, requests } = recordingClient(
      stubsFor(
        [offer('o-1', ZEBRA), offer('o-down', GAMMA)],
        {
          ...subscriptionRead(null, live({ pending })),
          ...scheduling(),
          'DELETE /api/v1/subscription/pending': { data: live() },
        },
        SUBSCRIBER,
      ),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('pending-change')).toBeTruthy());

    // On the row of the offer it names, and saying both the plan and the date
    // — both the server's answer.
    const row = document.querySelector('[data-offer="o-down"]');

    expect(row?.getAttribute('data-standing')).toBe('PENDING');
    expect(row?.textContent).toContain('Gamma');
    expect(row?.textContent).toContain(new Date(pending.effective_at).toLocaleDateString('en'));
    // A future change nobody can withdraw is a cancellation in disguise (§4.2).
    expect(row?.querySelector('[data-testid="move-down"]')).toBeNull();

    fireEvent.click(screen.getByTestId('cancel-pending-change'));

    await waitFor(() =>
      expect(
        requests.some(
          (request) => request.method === 'DELETE' && request.path === '/api/v1/subscription/pending',
        ),
      ).toBe(true),
    );

    expect(
      requests
        .filter((request) => request.method === 'DELETE')
        .map((request) => request.query),
    ).toEqual([{ seat: '1' }]);
  });

  it('says what a row commits to, which is not how often it is billed', async () => {
    // Periodicity is not commitment (non-negotiable #23): the row says both,
    // and the commitment comes from the version's own terms.
    render(
      clientFor([
        offer('o-1', ZEBRA, {
          version: { ...offer('o-1', ZEBRA).version, terms: { ...TERMS, commitment_months: 12 } },
        }),
      ]),
    );

    await waitFor(() => expect(screen.getByTestId('offer-commitment')).toBeTruthy());
    expect(screen.getByTestId('offer-commitment').textContent).toMatch(/12 months/);
  });
});

describe('the free period, said before the click (§6.4)', () => {
  it('offers it through its own door and not the checkout', async () => {
    const { client, requests } = recordingClient(
      stubsFor(
        [freePeriod(), offer('o-1', ZEBRA)],
        {
          ...subscriptionRead(null, null, false),
          'POST /api/v1/subscription/freemium': { data: live(), status: 201 },
        },
        SUBSCRIBER,
      ),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('start-freemium')).toBeTruthy());
    fireEvent.click(screen.getByTestId('start-freemium'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/freemium')).toBe(true),
    );

    // No order, no invoice and no payment: a €0 invoice would be a permanent
    // hole in a gapless legal series (§6.3), and `openCheckoutSession` refuses
    // this offer outright.
    expect(requests.some((request) => request.path === '/api/v1/checkout/sessions')).toBe(false);
    expect(
      requests
        .filter((request) => request.path === '/api/v1/subscription/freemium')
        .map((request) => request.body),
    ).toEqual([{ offer_id: 'o-free' }]);
  });

  it('withholds it from somebody who has already had one, and says so rather than letting the refusal arrive', async () => {
    const { client, requests } = recordingClient(
      stubsFor([freePeriod(), offer('o-1', ZEBRA)], subscriptionRead(null, null, true), SUBSCRIBER),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('freemium-spent')).toBeTruthy());

    const row = document.querySelector('[data-offer="o-free"]');

    expect(row?.getAttribute('data-standing')).toBe('SPENT');
    expect(row?.textContent).toMatch(/already had the free period/i);
    expect(row?.textContent).toMatch(/once and once only/i);
    // Not offered, and nothing was sent: the point of §6.4 is that
    // `FREEMIUM_ALREADY_USED` is never how somebody finds out.
    expect(screen.queryByTestId('start-freemium')).toBeNull();
    expect(requests.some((request) => request.path === '/api/v1/subscription/freemium')).toBe(false);
    // The paid offer beside it is still on sale.
    expect(
      document.querySelector('[data-offer="o-1"]')?.querySelector('[data-testid="buy-seat"]'),
    ).not.toBeNull();
  });

  it('tells a subscriber that leaving the plan above it is a cancellation and not a move down', async () => {
    // §6.2's consequence, and the row §6.4 asks to be honest: with the free
    // period spent there is nothing below the cheapest paid plan, so choosing
    // it is leaving — which is a different decision and a different button.
    const { client, requests } = recordingClient(
      stubsFor(
        [offer('o-1', ZEBRA), freePeriod()],
        { ...subscriptionRead(null, live(), true), ...scheduling(), ...previewing(MOVING_DOWN) },
        SUBSCRIBER,
      ),
    );

    render(client);

    await waitFor(() => expect(screen.getByTestId('freemium-spent')).toBeTruthy());

    const row = document.querySelector('[data-offer="o-free"]');

    expect(row?.getAttribute('data-standing')).toBe('SPENT');
    expect(screen.getByTestId('leaving-is-cancelling').textContent).toMatch(/leaving it is a cancellation/i);
    expect(row?.querySelector('[data-testid="move-down"]')).toBeNull();
    // And nothing was asked about it: a preview of a move the server refuses
    // would be a refusal rendered where a sentence belongs.
    expect(
      requests
        .filter((request) => request.path === '/api/v1/subscription/preview-change')
        .map((request) => request.body),
    ).not.toContainEqual({ offer_id: 'o-free', seat: true });
  });

  it('offers the free period as a move down to somebody who has never had one', async () => {
    // The other side of the same rule: with nothing spent, §6.2 makes
    // Lecture → Freemium an ordinary deferred move down.
    render(
      clientFor(
        [offer('o-1', ZEBRA), freePeriod()],
        {
          ...subscriptionRead(null, live(), false),
          ...scheduling(),
          ...previewing(MOVING_DOWN),
        },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('move-down')).toBeTruthy());
    expect(document.querySelector('[data-offer="o-free"]')?.getAttribute('data-standing')).toBe('DOWN');
    expect(screen.queryByTestId('freemium-spent')).toBeNull();
  });

  it('reads the free period from the contract and never from a plan’s name or a price of zero', async () => {
    // A free offer that **renews** is a free tier, not a free period (§6),
    // and the platform says so with `freemium: false`. A screen deriving it
    // from `price == 0` would offer the wrong door here.
    const tier = offer('o-tier', GAMMA, {
      version: {
        ...offer('o-tier', GAMMA).version,
        price: { minor_units: 0, currency: 'EUR' },
        freemium: false,
      },
    });

    render(clientFor([tier], subscriptionRead(null, null, true), SUBSCRIBER));

    await waitFor(() => expect(screen.getByTestId('buy-seat')).toBeTruthy());
    expect(screen.queryByTestId('freemium-spent')).toBeNull();
    expect(screen.queryByTestId('start-freemium')).toBeNull();
  });
});

describe('a priced move that has nobody to invoice', () => {
  it('explains the refusal rather than showing a bare code', async () => {
    // A move up raises an invoice, and an invoice is addressed to somebody
    // (ADR-057). Before the proration a change of plan billed nothing, so this
    // refusal could not reach a catalogue; now it is the cheapest thing a
    // customer can meet here.
    render(
      clientFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA)],
        {
          ...subscriptionRead(null, live()),
          ...scheduling(),
          ...previewing(MOVING_UP),
          'POST /api/v1/subscription/change-offer': {
            error: {
              error: {
                code: 'BILLING_PROFILE_REQUIRED',
                message: 'This tenant has no billing profile, so nothing can be invoiced to it.',
                details: {},
                request_id: 'req-1',
              },
            },
            status: 409,
          },
        },
        SUBSCRIBER,
      ),
    );

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-up'));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());

    const alert = screen.getByRole('alert');

    expect(alert.textContent).toMatch(/no billing address yet/i);
    expect(alert.textContent).toMatch(/nothing was charged/i);
    // On the row that asked, not at the top of an unrelated screen.
    expect(document.querySelector('[data-offer="o-up"]')?.contains(alert)).toBe(true);
  });
});

describe('somebody who may read but not change', () => {
  it('sees what each offer would do and is offered no button', async () => {
    // Hiding is courtesy — the API refuses either way — but a catalogue whose
    // buttons always fail is worse than one with prices and no buttons.
    const reader = { ...SESSION, permissions: ['catalog.read', 'subscription.read'] };

    render(
      clientFor(
        [offer('o-1', ZEBRA), offer('o-up', ALPHA)],
        { ...subscriptionRead(null, live()), ...scheduling(), ...previewing(MOVING_UP) },
        reader,
      ),
    );

    // The preview is a read, and `subscription.read` is what it needs — so the
    // row still says what moving would do, with nothing to press.
    await waitFor(() => expect(screen.getByTestId('what-it-would-do')).toBeTruthy());
    expect(screen.getByTestId('current-plan')).toBeTruthy();
    expect(screen.queryByTestId('move-up')).toBeNull();
    expect(screen.queryByTestId('confirm-cancel-seat')).toBeNull();
    expect(screen.queryByTestId('cancellation-decision')).toBeNull();
  });
});

/**
 * The half the screen used to drop on the floor (2026-09-28).
 *
 * The server has always answered `charge_invoice_id` and `credit_refund_id`;
 * nothing read them. So a customer moved up, an invoice with a gapless legal
 * number came into existence, and nobody was ever asked to pay it — it sat
 * unpaid until the dunning pass noticed and shut their workshop (ADR-060).
 */
describe('a change of plan pays for itself', () => {
  const CHARGED = { ...MOVING_UP, charge_invoice_id: 'inv-up', credit_refund_id: 'ref-1' };

  const STARTED = {
    id: 'pay-1',
    invoice_id: 'inv-up',
    subscription_id: null,
    provider: 'stripe',
    provider_payment_id: 'pi_1',
    status: 'PENDING',
    settled: false,
    final: false,
    amount: { minor_units: 3900, currency: 'EUR' },
    method: null,
    failure_code: null,
    failure_reason: null,
    succeeded_at: null,
    failed_at: null,
    created_at: '2026-09-28T09:00:00Z',
    client_secret: 'pi_1_secret',
    payment_provider: { provider: 'stripe', publishable_key: 'pk_test' },
  };

  function movingUp(extra: Record<string, Stub | (() => Stub)> = {}, change: unknown = CHARGED) {
    return stubsFor(
      [offer('o-1', ZEBRA), offer('o-up', ALPHA)],
      {
        ...subscriptionRead(null, live()),
        ...scheduling(),
        ...previewing(MOVING_UP),
        'POST /api/v1/subscription/change-offer': { data: { subscription: live(), change } },
        ...extra,
      },
      SUBSCRIBER,
    );
  }

  it('asks the server for a payment on the invoice the change raised', async () => {
    const { client, requests } = recordingClient(
      movingUp({
        'POST /api/v1/billing/invoices/{invoiceId}/payments': { data: STARTED, status: 201 },
      }),
    );
    render(client);

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-up'));

    // The invoice the *server* named, never one the screen guessed: a
    // document's number is never invented, and neither is its id.
    await waitFor(() =>
      expect(
        requests.some(
          (request) =>
            request.path === '/api/v1/billing/invoices/{invoiceId}/payments' &&
            (request.pathParams as { invoiceId?: string } | undefined)?.invoiceId === 'inv-up',
        ),
      ).toBe(true),
    );

    // And the way back is there whatever the card form does.
    await waitFor(() =>
      expect(screen.getByTestId('continue-to-invoice').getAttribute('href')).toContain('inv-up'),
    );
  });

  it('says what went back to the card, because that money has already moved', async () => {
    render(
      stubClient(
        movingUp({
          'POST /api/v1/billing/invoices/{invoiceId}/payments': { data: STARTED, status: 201 },
        }),
      ),
    );

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-up'));

    // In the past tense and with no button: the refund was sent before the
    // plan moved (ADR-058), so there is nothing for the customer to do.
    await waitFor(() => expect(screen.getByTestId('settle-credit')).toBeTruthy());
    // 900 minor units, through `Money` and therefore through `Intl` — the
    // separator is the locale’s, so the assertion is about the figure and not
    // about which punctuation this machine happens to use.
    expect(screen.getByTestId('settle-credit').textContent).toMatch(/9[.,]00/);
  });

  it('asks for no payment when the move raised no document', async () => {
    // Nothing outstanding raises no document at all (§6.3) — a €0 invoice
    // would be a permanent hole in a gapless legal series. The screen must
    // not then invent a payment, nor take itself over to say nothing.
    const free = { ...MOVING_UP, charge_invoice_id: null, credit_refund_id: null };
    const { client, requests } = recordingClient(movingUp({}, free));
    render(client);

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-up'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/change-offer')).toBe(true),
    );

    expect(
      requests.some((request) => request.path === '/api/v1/billing/invoices/{invoiceId}/payments'),
    ).toBe(false);
    expect(screen.queryByTestId('catalogue-settle')).toBeNull();
    expect(screen.getByTestId('move-up')).toBeTruthy();
  });

  it('draws no card form until the server has answered with a secret', async () => {
    // Waiting is the honest state. A panel rendered ahead of the secret would
    // be the optimism `gate:money` forbids, and the secret is returned once
    // and never recoverable (ADR-034) — there is nothing to draw without it.
    render(
      stubClient(
        movingUp({
          // Held open: this is the window an optimistic implementation would
          // have filled with a form it had no secret for.
          'POST /api/v1/billing/invoices/{invoiceId}/payments': {
            data: STARTED,
            status: 201,
            delayMs: 5_000,
          },
        }),
      ),
    );

    await waitFor(() => expect(screen.getByTestId('move-up')).toBeTruthy());
    fireEvent.click(screen.getByTestId('move-up'));

    // The move has landed and the screen says so, with no form on it yet.
    await waitFor(() => expect(screen.getByTestId('catalogue-settle')).toBeTruthy());
    expect(screen.queryByTestId('payment-element')).toBeNull();

  });
});

describe('leaving early pays for itself too', () => {
  const CHARGED = { ...IF_CANCELLED, charge_invoice_id: 'inv-exit' };

  const STARTED = {
    id: 'pay-2',
    invoice_id: 'inv-exit',
    subscription_id: null,
    provider: 'stripe',
    provider_payment_id: 'pi_2',
    status: 'PENDING',
    settled: false,
    final: false,
    amount: { minor_units: 8700, currency: 'EUR' },
    method: null,
    failure_code: null,
    failure_reason: null,
    succeeded_at: null,
    failed_at: null,
    created_at: '2026-09-28T09:00:00Z',
    client_secret: 'pi_2_secret',
    payment_provider: { provider: 'stripe', publishable_key: 'pk_test' },
  };

  function leaving(cancellation: unknown, extra: Record<string, Stub | (() => Stub)> = {}) {
    return stubsFor(
      [offer('o-1', ZEBRA)],
      {
        ...subscriptionRead(null, live()),
        ...scheduling(),
        'POST /api/v1/subscription/cancel': { data: { subscription: live(), cancellation } },
        ...extra,
      },
      SUBSCRIBER,
    );
  }

  it('collects the buy-out on the way out', async () => {
    const { client, requests } = recordingClient(
      leaving(CHARGED, {
        'POST /api/v1/billing/invoices/{invoiceId}/payments': { data: STARTED, status: 201 },
      }),
    );
    render(client);

    await waitFor(() => expect(screen.getByTestId('confirm-cancel-seat')).toBeTruthy());
    fireEvent.click(screen.getByTestId('confirm-cancel-seat'));
    await waitFor(() => expect(screen.getByTestId('cancel-seat')).toBeTruthy());
    fireEvent.click(screen.getByTestId('cancel-seat'));

    await waitFor(() =>
      expect(
        requests.some(
          (request) =>
            request.path === '/api/v1/billing/invoices/{invoiceId}/payments' &&
            (request.pathParams as { invoiceId?: string } | undefined)?.invoiceId === 'inv-exit',
        ),
      ).toBe(true),
    );
  });

  it('asks for nothing when leaving cost nothing', async () => {
    const { client, requests } = recordingClient(
      leaving({ ...IF_CANCELLED, chargeable_months: 0, charge_invoice_id: null }),
    );
    render(client);

    await waitFor(() => expect(screen.getByTestId('confirm-cancel-seat')).toBeTruthy());
    fireEvent.click(screen.getByTestId('confirm-cancel-seat'));
    await waitFor(() => expect(screen.getByTestId('cancel-seat')).toBeTruthy());
    fireEvent.click(screen.getByTestId('cancel-seat'));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/subscription/cancel')).toBe(true),
    );

    expect(
      requests.some((request) => request.path === '/api/v1/billing/invoices/{invoiceId}/payments'),
    ).toBe(false);
    expect(screen.queryByTestId('catalogue-settle')).toBeNull();
  });
});