import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { CatalogueScreen } from './CatalogueScreen';

/**
 * The platform pricing its own product.
 *
 * The chain under test is an order of operations, not a set of forms: nothing
 * can be priced until a plan exists, an offer is born a draft, and publishing
 * is what puts a price on sale. A screen that let somebody write an offer with
 * no plan would send a request the API refuses, and a screen that published
 * quietly would freeze a price nobody meant to freeze.
 */
const PRODUCT = { id: 'p-1', code: 'atlas', name: 'Atlas' };
const PRO = { id: 'plan-1', code: 'pro', name: 'Pro', rank: 10 };
const FREE = { id: 'plan-2', code: 'free', name: 'Free', rank: 0 };

const QUOTA = { id: 'f-1', code: 'projects', name: 'Projects', kind: 'QUOTA', unit: 'projects' };

const version = (n: number, status: string, minorUnits: number) => ({
  id: `v-${n}`,
  version: n,
  status,
  billing_period: 'MONTHLY',
  price: { minor_units: minorUnits, currency: 'EUR' },
  valid_from: '2026-01-01T00:00:00Z',
  valid_until: null,
  grants: [],
  terms: null,
});

const OFFER = {
  id: 'offer-1',
  code: 'pro-monthly',
  name: 'Pro, monthly',
  plan: PRO,
  publicly_listed: false,
  versions: [version(1, 'DRAFT', 2900)],
};

const ROUTE = {
  path: '/console/catalogue',
  initial: '/console/catalogue?selected=atlas',
} as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/catalogue': {
      data: { product: PRODUCT, plans: [FREE, PRO], features: [QUOTA] },
    },
    'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [OFFER] } },
    'POST /api/v1/staff/catalogue/plans': { status: 201, data: { plan: PRO } },
    'PATCH /api/v1/staff/catalogue/plans/{planId}': { data: { plan: { ...PRO, rank: 30 } } },
    'POST /api/v1/staff/catalogue/features': { status: 201, data: { feature: QUOTA } },
    'POST /api/v1/staff/catalogue/offers': { status: 201, data: { offer: OFFER } },
    'POST /api/v1/staff/catalogue/offers/{offerId}/versions': { status: 201, data: { offer: OFFER } },
    'POST /api/v1/staff/catalogue/offers/{offerId}/publish': {
      data: { offer: { ...OFFER, versions: [version(1, 'ACTIVE', 2900)] } },
    },
    ...extra,
  });
}

describe('without a product', () => {
  it('says where to pick one rather than showing an empty catalogue', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), { path: '/console/catalogue' });

    // The console has no ambient product, because a platform role grants no
    // membership — so it says so and points at Products.
    await waitFor(() => expect(screen.getByText(/No product chosen/i)).toBeTruthy());
    expect(screen.getByRole('link', { name: /Go to Products/i })).toBeTruthy();
  });
});

describe('plans', () => {
  it('shows them in rank order, which is the only ordering there is', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('plan-list')).toBeTruthy());

    // Free has rank 0 and comes first. Nothing compares the names.
    const codes = [...document.querySelectorAll('[data-plan]')].map((el) =>
      el.getAttribute('data-plan'),
    );

    expect(codes).toEqual(['free', 'pro']);
  });

  it('sends a lowercased code with the rank', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [] } },
      'POST /api/v1/staff/catalogue/plans': { status: 201, data: { plan: PRO } },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Plan code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Plan code'), { target: { value: 'PRO' } });
    fireEvent.change(screen.getByLabelText('Plan name'), { target: { value: 'Pro' } });
    fireEvent.change(screen.getByLabelText('Rank'), { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add plan' }));

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/staff/catalogue/plans')).toBe(true),
    );

    expect(requests.find((r) => r.path === '/api/v1/staff/catalogue/plans')?.body).toEqual({
      code: 'pro',
      name: 'Pro',
      rank: 10,
    });
  });

  it('reorders only when the rank actually changed', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [PRO], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [] } },
      'PATCH /api/v1/staff/catalogue/plans/{planId}': { data: { plan: { ...PRO, rank: 30 } } },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Rank of Pro')).toBeTruthy());

    // Blurred without touching it: a REORDER in the audit trail would be a
    // commercial decision nobody made.
    fireEvent.blur(screen.getByLabelText('Rank of Pro'));
    expect(requests.some((r) => r.method === 'PATCH')).toBe(false);

    fireEvent.change(screen.getByLabelText('Rank of Pro'), { target: { value: '30' } });
    fireEvent.blur(screen.getByLabelText('Rank of Pro'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ rank: 30 });
  });

  it('says a plan cannot be deleted, and offers no way to', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('plan-list')).toBeTruthy());

    expect(screen.getByText(/cannot be deleted/i)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });
});

describe('features', () => {
  it('refuses to send a unit on a switch', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [PRO], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [] } },
      'POST /api/v1/staff/catalogue/features': { status: 201, data: { feature: QUOTA } },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Feature code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Feature code'), { target: { value: 'api' } });
    fireEvent.change(screen.getByLabelText('Feature name'), { target: { value: 'API access' } });
    fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'BOOLEAN' } });

    // The field is disabled rather than hidden, so the rule is visible instead
    // of being discovered through a 400.
    expect(screen.getByLabelText<HTMLInputElement>('Unit').disabled).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Add feature' }));

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/staff/catalogue/features')).toBe(true),
    );

    expect(requests.find((r) => r.path === '/api/v1/staff/catalogue/features')?.body).toEqual({
      code: 'api',
      name: 'API access',
      kind: 'BOOLEAN',
      unit: null,
    });
  });

  it('says the kind cannot be changed afterwards', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('feature-list')).toBeTruthy());

    expect(screen.getByText(/cannot be changed afterwards/i)).toBeTruthy();
    expect(screen.getByText(/reinterpret prices somebody is already paying/i)).toBeTruthy();
  });
});

describe('offers', () => {
  it('will not offer the form until a plan exists', async () => {
    renderAtRoute(
      <CatalogueScreen />,
      clientFor({
        'GET /api/v1/staff/catalogue': {
          data: { product: PRODUCT, plans: [], features: [] },
        },
        'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [] } },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('needs-a-plan')).toBeTruthy());

    // The API would refuse it — its insert selects the plan by id — so the
    // screen says why instead of sending a request that cannot succeed.
    expect(screen.queryByLabelText('Offer code')).toBeNull();
  });

  it('sends minor units and the chosen plan', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [PRO], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [] } },
      'POST /api/v1/staff/catalogue/offers': { status: 201, data: { offer: OFFER } },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Offer code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Offer code'), { target: { value: 'pro-monthly' } });
    fireEvent.change(screen.getByLabelText('Offer name'), { target: { value: 'Pro, monthly' } });
    fireEvent.change(screen.getByLabelText('Plan'), { target: { value: PRO.id } });
    fireEvent.change(screen.getByLabelText('Price in minor units'), { target: { value: '2900' } });
    fireEvent.click(screen.getByRole('button', { name: /Add offer as a draft/i }));

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/staff/catalogue/offers')).toBe(true),
    );

    // 2900, never 29.00: a cent lost to binary rounding is a cent an auditor
    // asks about.
    expect(requests.find((r) => r.path === '/api/v1/staff/catalogue/offers')?.body).toEqual({
      code: 'pro-monthly',
      name: 'Pro, monthly',
      plan_id: PRO.id,
      billing_period: 'MONTHLY',
      price_minor_units: 2900,
      currency: 'EUR',
    });
  });

  it('shows a draft as a draft and offers to publish it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [PRO], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [OFFER] } },
      'POST /api/v1/staff/catalogue/offers/{offerId}/publish': {
        data: { offer: { ...OFFER, versions: [version(1, 'ACTIVE', 2900)] } },
      },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByTestId('offer-list')).toBeTruthy());

    expect(document.querySelector('[data-version="1"]')?.getAttribute('data-status')).toBe('DRAFT');

    fireEvent.click(screen.getByRole('button', { name: 'Publish' }));

    await waitFor(() =>
      expect(
        requests.some((r) => r.path === '/api/v1/staff/catalogue/offers/{offerId}/publish'),
      ).toBe(true),
    );

    expect(
      requests.find((r) => r.path === '/api/v1/staff/catalogue/offers/{offerId}/publish')?.body,
    ).toEqual({ version: 1 });
  });

  it('says what publishing does, beside the button', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('offer-list')).toBeTruthy());

    // ADR-033 freezes a published version. A button that did that quietly
    // would freeze a price nobody meant to freeze.
    expect(screen.getByText(/can never be edited/i)).toBeTruthy();
  });

  it('offers a new version rather than an edit on a published price', async () => {
    const published = { ...OFFER, versions: [version(1, 'ACTIVE', 2900)] };

    const { client, requests } = recordingClient({
      'GET /api/v1/staff/catalogue': {
        data: { product: PRODUCT, plans: [PRO], features: [] },
      },
      'GET /api/v1/staff/storefront/offers': { data: { product: PRODUCT, offers: [published] } },
      'POST /api/v1/staff/catalogue/offers/{offerId}/versions': {
        status: 201,
        data: { offer: published },
      },
    });

    renderAtRoute(<CatalogueScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByTestId('offer-list')).toBeTruthy());

    // No edit control on an ACTIVE version — the absence is the design.
    expect(screen.queryByRole('button', { name: 'Publish' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /New version from this/i }));

    await waitFor(() =>
      expect(
        requests.some((r) => r.path === '/api/v1/staff/catalogue/offers/{offerId}/versions'),
      ).toBe(true),
    );
  });

  it('surfaces a refusal to publish rather than looking as though it worked', async () => {
    renderAtRoute(
      <CatalogueScreen />,
      clientFor({
        'POST /api/v1/staff/catalogue/offers/{offerId}/publish': {
          status: 409,
          error: {
            error: {
              code: 'OFFER_ALREADY_ON_SALE',
              message: 'Another version is on sale.',
              details: {},
              request_id: 'r',
            },
          },
        },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('offer-list')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(document.querySelector('[data-version="1"]')?.getAttribute('data-status')).toBe('DRAFT');
  });

  it('names the product it is pricing', async () => {
    renderAtRoute(<CatalogueScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('plan-list')).toBeTruthy());

    // Three sections all scoped to one product, and the console has no ambient
    // one — so it has to be visible.
    expect(screen.getByText(/Atlas/)).toBeTruthy();
  });
});
