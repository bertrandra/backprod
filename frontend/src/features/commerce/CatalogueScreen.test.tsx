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
// An administrator: buys for the organisation (`billing.manage`), for
// themselves (`billing.pay`), and raises quotes.
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

function subscriptionRead(subscription: unknown, seat: unknown = null) {
  return { 'GET /api/v1/subscription': { data: { subscription, seat, history: [], events: [] } } };
}

const live = () => ({
  id: 'sub-1',
  status: 'ACTIVE',
  offer: { id: 'o-1', code: 'zebra-monthly', name: 'Zebra monthly', plan: ZEBRA, version: offer('o-1', ZEBRA).version },
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

// A private person, which is what signing up without a company leaves.
const PERSON = {
  ...BUSINESS,
  customer_kind: 'B2C',
  taxable_person: false,
  vat_number: null,
  vat_number_status: null,
  vat_number_verified_at: null,
  vat_number_country: null,
};

const ZEBRA = { id: 'p-zebra', code: 'ZEBRA', name: 'Zebra', rank: 10 };
const ALPHA = { id: 'p-alpha', code: 'ALPHA', name: 'Alpha', rank: 20 };

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
      grants: [],
    },
    ...overrides,
  };
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
    'GET /api/v1/plans': { data: { plans: [ALPHA, ZEBRA] } },
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
  it('offers a quote and both purchases to a business whose administrator may do all three', async () => {
    render(clientFor([offer('o-1', ZEBRA)]));

    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy();
    await waitFor(() => expect(screen.getByRole('button', { name: /^quote$/i })).toBeTruthy());
  });

  it('offers a private person the purchases and not the quote', async () => {
    // Same permissions, different customer: the server refuses a quote for a
    // tenant whose tax profile does not say B2B, so the button is not there.
    // Not a gate on a role or a plan — on the customer's own declared kind.
    render(clientFor([offer('o-1', ZEBRA)], { 'GET /api/v1/tax/profile': { data: { profile: PERSON } } }));

    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^quote$/i })).toBeNull();
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

  it('still offers a seat while the organisation is subscribed, and only then withholds the organisation\'s', async () => {
    // One live subscription per product for the organisation (409
    // SUBSCRIPTION_ALREADY_ACTIVE), so that button and the quote are not
    // there and the person is pointed at the subscription instead. A seat
    // stands beside it (§13.1): the operator's default organisation is
    // subscribed, and a newcomer still has something to buy.
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(live()), subscriber));

    await waitFor(() => expect(screen.getByTestId('already-subscribed')).toBeTruthy());
    expect(screen.getByTestId('already-subscribed').textContent).toContain('Zebra monthly');
    expect(screen.getByTestId('already-subscribed').textContent).toMatch(/already subscribed/i);
    expect(screen.queryByRole('button', { name: TENANT_BUTTON })).toBeNull();
    // Said where the button was, not only at the top: a button that vanishes
    // without a word reads as a screen that lost something.
    expect(screen.getByTestId('tenant-subscribed').textContent).toMatch(/already subscribed/i);
    expect(screen.queryByTestId('seat-taken')).toBeNull();
    expect(screen.queryByRole('button', { name: /^quote$/i })).toBeNull();
    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    // Still a catalogue: the prices are what `catalog.read` is for.
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });

  it('withholds the seat while the person already holds one, and not the organisation\'s', async () => {
    // The mirror: one live seat per person (409 SEAT_ALREADY_ACTIVE).
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(null, live()), subscriber));

    await waitFor(() => expect(screen.getByTestId('already-seated')).toBeTruthy());
    expect(screen.getByTestId('already-seated').textContent).toContain('Zebra monthly');
    expect(screen.queryByRole('button', { name: SEAT_BUTTON })).toBeNull();
    expect(screen.getByTestId('seat-taken').textContent).toMatch(/your seat is live/i);
    expect(screen.queryByTestId('tenant-subscribed')).toBeNull();
    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    expect(screen.queryByTestId('already-subscribed')).toBeNull();
  });

  it('offers both once the subscription read says there is neither', async () => {
    const subscriber = { ...BUYER, permissions: [...BUYER.permissions, 'subscription.read'] };

    render(clientFor([offer('o-1', ZEBRA)], subscriptionRead(null), subscriber));

    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy();
    expect(screen.queryByTestId('already-subscribed')).toBeNull();
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
    expect(screen.queryByTestId('seat-taken')).toBeNull();
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

    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: TENANT_BUTTON }));

    // Not a navigation: the secret is returned once (ADR-034) and the order
    // page cannot hold it, so leaving now would leave an order nobody can pay.
    await waitFor(() => expect(screen.getByTestId('catalogue-pay')).toBeTruthy());
    expect(location()).not.toContain('/checkout/');
    // For the organisation: said to the server, and said on the page.
    expect(requests.filter((request) => request.method === 'POST').map((request) => request.body)).toEqual([
      { offer_id: 'o-1', seat: false },
    ]);
    expect(screen.getByTestId('catalogue-pay').getAttribute('data-seat')).toBe('false');
    expect(screen.getByTestId('catalogue-pay').textContent).toContain('for the organisation');
    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
    // The server's amount on the button, never re-derived here.
    expect(screen.getByRole('button', { name: /Pay/ }).querySelector('[data-minor-units="3480"]')).not.toBeNull();

    // The session id is the order id, so the link names the order.
    fireEvent.click(screen.getByTestId('continue-to-order'));
    await waitFor(() => expect(location()).toContain('/checkout/order-1'));
  });

  it('buys a seat with the flag, and says whose it is', async () => {
    const { client, requests } = recordingClient(
      stubsFor([offer('o-1', ZEBRA)], {
        'POST /api/v1/checkout/sessions': { data: { session: { ...OPENED, seat: true } }, status: 201 },
      }),
    );
    render(client);

    await waitFor(() => expect(screen.getByRole('button', { name: SEAT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: SEAT_BUTTON }));

    await waitFor(() => expect(screen.getByTestId('catalogue-pay')).toBeTruthy());
    // A flag, never an id: whose seat it is, the server already knows.
    expect(requests.filter((request) => request.method === 'POST').map((request) => request.body)).toEqual([
      { offer_id: 'o-1', seat: true },
    ]);
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

    await waitFor(() => expect(screen.getByRole('button', { name: TENANT_BUTTON })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: TENANT_BUTTON }));

    await waitFor(() => expect(screen.getByTestId('continue-to-order')).toBeTruthy());
    expect(screen.queryByTestId('payment-panel')).toBeNull();
  });

  it('goes to the quote it just raised', async () => {
    const { location } = render(
      clientFor([offer('o-1', ZEBRA)], {
        'POST /api/v1/sales/quotes': {
          data: {
            id: 'quote-1',
            status: 'SENT',
            open: true,
            offer_version_id: 'v-o-1',
            net: { minor_units: 2900, currency: 'EUR' },
            vat: { minor_units: 580, currency: 'EUR' },
            gross: { minor_units: 3480, currency: 'EUR' },
            valid_until: '2026-02-01T00:00:00Z',
            customer: {},
            sent_at: '2026-01-01T00:00:00Z',
            decided_at: null,
            created_at: '2026-01-01T00:00:00Z',
            lines: [],
          },
          status: 201,
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^quote$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^quote$/i }));

    await waitFor(() => expect(location()).toContain('selected=quote-1'));
  });
});

describe('an empty catalogue', () => {
  it('explains itself instead of looking broken', async () => {
    render(clientFor([]));

    await waitFor(() => expect(screen.getByText(/nothing is on sale/i)).toBeTruthy());
    expect(screen.getByText(/publishing one puts it here/i)).toBeTruthy();
  });
});
