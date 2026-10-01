import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProjectsScreen } from './ProjectsScreen';

/**
 * The product, on the screen a person lands on (2026-09-22).
 *
 * Two facts and one door: where the product lives when it is beside the
 * platform, what the organisation holds on it, and the way over — which is
 * the switcher's, so a token never travels. The subscription is the server's
 * word: the status shown is the one answered, and a period end is a date
 * read, not a verdict computed.
 */
const READER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'projects.read', 'subscription.read'],
};

const PLAN = { id: 'p-plan', code: 'plan', name: 'Plan', app_url: 'https://plan.example.test' };
const ATLAS = { id: 'p-1', code: 'atlas', name: 'Atlas', app_url: null };

const SUBSCRIPTION = {
  id: 'sub-1',
  status: 'ACTIVE',
  offer: {
    id: 'o-1',
    code: 'pro-monthly',
    name: 'Pro monthly',
    plan: { id: 'plan-1', code: 'pro', name: 'Pro', rank: 1 },
    version: { id: 'v-1', version: 1, billing_period: 'MONTHLY', price: { amount: 2900, currency: 'EUR' } },
  },
  started_at: '2026-09-01T09:00:00Z',
  current_period_start: '2026-09-01T09:00:00Z',
  current_period_end: '2026-10-01T09:00:00Z',
  cancel_at_period_end: false,
  cancel_effective_at: null,
  cancelled_at: null,
  subscriber: { kind: 'TENANT', user_id: null },
  terms: { term_months: null, commitment_months: 0, notice_days: 0, cancellation_policy: 'AT_PERIOD_END', early_termination: 'FREE', renewal: 'AUTO_RENEW' },
  ended_at: null,
  owner_user_id: null,
};

function clientFor(
  session: unknown,
  products: unknown[],
  subscription: unknown,
  extra: Record<string, Stub> = {},
  own: { seat?: unknown; organisation_subscribed?: boolean; coverage?: unknown } = {},
) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/products': { data: { products, default: null, memberships: [], pending: [] } },
    'GET /api/v1/subscription': {
      data: {
        subscription,
        seat: own.seat ?? null,
        organisation_subscribed: own.organisation_subscribed ?? subscription !== null,
        coverage: own.coverage ?? null,
        history: [],
        events: [],
      },
    },
    'GET /api/v1/projects': { data: { projects: [], total: 0, limit: 25, offset: 0 } },
    'GET /api/v1/products/{productId}/configuration': { data: { configuration: { project_schema_versions: { supported: [1] } } } },
    ...extra,
  });
}

describe('the product card', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('names where the product lives, what the organisation holds, and opens it the way the switcher does', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, search: '', href: 'http://localhost/projects' });

    renderAtRoute(<ProjectsScreen />, clientFor(READER, [ATLAS, PLAN], SUBSCRIPTION), { path: '/projects', product: 'plan' });

    const card = await screen.findByTestId('product-card');
    expect(card.getAttribute('data-product')).toBe('plan');
    expect(screen.getByTestId('product-address').textContent).toContain('plan.example.test');

    // The subscription as the server answered it — status, offer, plan, the
    // period end — never a date turned into a verdict here.
    const summary = await screen.findByTestId('subscription-summary');
    expect(summary.querySelector('[data-status]')?.getAttribute('data-status')).toBe('ACTIVE');
    expect(summary.textContent).toContain('Pro monthly');
    expect(summary.textContent).toContain('renews');

    // The door: a full navigation with the product code and nothing else —
    // no credential, and no language, which is the person's and which the
    // product reads from the platform.
    fireEvent.click(screen.getByTestId('open-product'));
    await waitFor(() => expect(assign).toHaveBeenCalledTimes(1));
    const target = new URL(String(assign.mock.calls[0]?.[0]));
    expect(target.origin).toBe('https://plan.example.test');
    expect(target.searchParams.get('product')).toBe('plan');
    expect([...target.searchParams.keys()]).toEqual(['product']);
  });

  it('shows the seat the person holds, which is the only thing the tenant surface sells', async () => {
    // The bug this closes: the card read the *organisation's* subscription
    // alone, and since ADR-055 the tenant surface sells seats only. So the
    // ordinary customer — somebody who bought a seat for themselves — landed
    // on Projects and was told "No subscription on this product yet" while
    // holding one.
    const seat = {
      ...SUBSCRIPTION,
      id: 'seat-1',
      subscriber: { kind: 'USER', user_id: 'u-1' },
    };

    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], null, {}, { seat, organisation_subscribed: false }),
      { path: '/projects', product: 'plan' },
    );

    const summary = await screen.findByTestId('subscription-summary');

    expect(screen.queryByTestId('subscription-none')).toBeNull();
    expect(summary.getAttribute('data-scope')).toBe('seat');
    expect(summary.textContent).toContain('Pro monthly');
    // Whose it is, because a seat and the organisation's are bought and
    // cancelled by different people.
    expect(summary.textContent).toContain('your seat');
  });

  it('prefers the seat over the organisation’s, because the seat is theirs', async () => {
    const seat = {
      ...SUBSCRIPTION,
      id: 'seat-1',
      subscriber: { kind: 'USER', user_id: 'u-1' },
      offer: { ...SUBSCRIPTION.offer, name: 'Pro yearly' },
    };

    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], SUBSCRIPTION, {}, { seat }),
      { path: '/projects', product: 'plan' },
    );

    const summary = await screen.findByTestId('subscription-summary');

    expect(summary.getAttribute('data-scope')).toBe('seat');
    expect(summary.textContent).toContain('Pro yearly');
  });

  it('falls back to the organisation’s for somebody it covers, and says so', async () => {
    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], SUBSCRIPTION, {}, { seat: null }),
      { path: '/projects', product: 'plan' },
    );

    const summary = await screen.findByTestId('subscription-summary');

    expect(summary.getAttribute('data-scope')).toBe('organisation');
    expect(summary.textContent).toContain('the organisation');
  });

  it('tells somebody left off their organisation’s subscription, rather than saying there is none', async () => {
    // The server withholds it from a member it does not cover (ADR-053) and
    // says so with the flag. Shown as "none" here, the card would disagree
    // with the Subscription screen, which says "you are not on it".
    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], null, {}, { seat: null, organisation_subscribed: true }),
      { path: '/projects', product: 'plan' },
    );

    expect(await screen.findByTestId('subscription-withheld')).toBeTruthy();
    expect(screen.queryByTestId('subscription-none')).toBeNull();
  });
  it('says a colleague’s subscription covers them, rather than that there is none', async () => {
    // Somebody added to a colleague's seat holds none of their own. The card
    // said "No subscription on this product yet" to them too — to a person
    // whose projects load on the very same screen.
    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], null, {}, {
        seat: null,
        organisation_subscribed: false,
        coverage: {
          subscription_id: 'sub-9',
          status: 'ACTIVE',
          current_period_end: null,
          own: false,
        },
      }),
      { path: '/projects', product: 'plan' },
    );

    const said = await screen.findByTestId('covered-by-a-colleague');

    expect(said.textContent).toMatch(/colleague/i);
    expect(screen.queryByTestId('subscription-none')).toBeNull();
  });

  it('has no door for a product whose screens are this workspace, and says when nothing is subscribed', async () => {
    renderAtRoute(<ProjectsScreen />, clientFor(READER, [ATLAS, PLAN], null), { path: '/projects', product: 'atlas' });

    await screen.findByTestId('product-card');
    expect(screen.queryByTestId('open-product')).toBeNull();
    expect(screen.queryByTestId('product-address')).toBeNull();
    expect(await screen.findByTestId('subscription-none')).toBeTruthy();
  });

  it('says a cancellation is an end, not a renewal', async () => {
    renderAtRoute(
      <ProjectsScreen />,
      clientFor(READER, [PLAN], { ...SUBSCRIPTION, cancel_at_period_end: true }),
      { path: '/projects', product: 'plan' },
    );

    const summary = await screen.findByTestId('subscription-summary');
    expect(summary.textContent).toContain('ends');
    expect(summary.textContent).not.toContain('renews');
  });

  it('is not shown to somebody who may not read the subscription', async () => {
    const withoutRead = { ...SESSION, permissions: [...SESSION.permissions, 'projects.read'] };

    renderAtRoute(<ProjectsScreen />, clientFor(withoutRead, [PLAN], SUBSCRIPTION), { path: '/projects', product: 'plan' });

    await screen.findByText('No projects yet');
    expect(screen.queryByTestId('product-card')).toBeNull();
  });
});
