import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { DirectoryScreen } from './DirectoryScreen';

/**
 * U8's second exit criterion: **an erased user still appears in the directory,
 * carrying `erased_at` and no identity — the row surviving is the design.**
 *
 * The failure this guards against is a screen that treats a null email as a
 * rendering problem: showing "—" where a name would be, or worse, filtering the
 * row out because it looks broken. Either would turn "this person asked to be
 * forgotten" into "this person never existed", which is a different and false
 * claim, and would leave every invoice pointing at a row nobody can find.
 */
const ERASED = {
  id: '11111111-1111-4111-8111-111111111111',
  email: null,
  display_name: null,
  created_at: '2025-01-01T10:00:00Z',
  erased_at: '2026-05-01T10:00:00Z',
  tenants: 2,
};

const PRESENT = {
  id: '22222222-2222-4222-8222-222222222222',
  email: 'ada@acme.test',
  display_name: 'Ada',
  created_at: '2025-06-01T10:00:00Z',
  erased_at: null,
  tenants: 1,
};

const TENANT = {
  id: '33333333-3333-4333-8333-333333333333',
  name: 'Acme Ltd',
  slug: 'acme',
  created_at: '2025-01-01T10:00:00Z',
  members: 4,
  active_subscriptions: 1,
  unpaid_invoices: 2,
};

const INVOICE = {
  id: '44444444-4444-4444-8444-444444444444',
  number: null,
  tenant_id: TENANT.id,
  tenant_name: 'Acme Ltd',
  status: 'DRAFT',
  currency: 'EUR',
  net_minor_units: 2900,
  vat_minor_units: 580,
  gross_minor_units: 3480,
  issued_at: null,
  due_at: null,
  paid_at: null,
};

const page = (key: string, rows: unknown[]) => ({
  data: { [key]: rows, total: rows.length, limit: 25, offset: 0 },
});

const PAYMENT = {
  id: '77777777-7777-4777-8777-777777777777',
  tenant_id: TENANT.id,
  tenant_name: 'Acme Ltd',
  product_code: 'plan',
  invoice_id: INVOICE.id,
  invoice_number: '2026-000004',
  customer_name: 'Ada Lovelace',
  customer_email: 'ada@acme.test',
  subscription_id: null,
  status: 'SUCCEEDED',
  amount_minor_units: 3480,
  currency: 'EUR',
  provider: 'stripe',
  provider_payment_id: 'pi_1',
  method: 'CARD',
  failure_code: null,
  failure_reason: null,
  succeeded_at: '2026-09-20T10:00:00Z',
  failed_at: null,
  created_at: '2026-09-20T09:59:00Z',
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/admin/tenants': page('tenants', [TENANT]),
    'GET /api/v1/admin/users': page('users', [PRESENT, ERASED]),
    'GET /api/v1/admin/invoices': page('invoices', [INVOICE]),
    'GET /api/v1/admin/subscriptions': page('subscriptions', []),
    'GET /api/v1/admin/payments': page('payments', [PAYMENT]),
    ...extra,
  });
}

const at = (tab?: string) => ({
  path: '/console/directory',
  ...(tab === undefined ? {} : { initial: `/console/directory?tab=${tab}` }),
});

describe('an erased person', () => {
  it('still has a row', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('users'));

    await waitFor(() =>
      expect(document.querySelector(`[data-directory-row="${ERASED.id}"]`)).not.toBeNull(),
    );
    // Two rows: the person who is here and the person who was.
    expect(document.querySelectorAll('[data-directory-row]')).toHaveLength(2);
  });

  it('is shown as erased rather than as a person with missing fields', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('users'));

    await waitFor(() => expect(screen.getByTestId('erased-identity')).toBeTruthy());

    const row = document.querySelector(`[data-directory-row="${ERASED.id}"]`);

    expect(row?.getAttribute('data-erased')).toBe('true');
    expect(screen.getByTestId('erased-identity').textContent).toMatch(/asked to be forgotten/i);
    // Not a dash where a name would be, and no invented placeholder.
    expect(row?.textContent).not.toMatch(/no name|no email/i);
  });

  it('keeps the identifier and the date, which is what the record needs', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('users'));

    await waitFor(() => expect(screen.getByTestId('erased-identity')).toBeTruthy());

    const row = document.querySelector(`[data-directory-row="${ERASED.id}"]`);

    expect(row?.textContent).toContain(ERASED.id);
    expect(row?.textContent).toMatch(/erased/i);
  });

  it('is distinguished from a person who simply has no display name', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/users': page('users', [
          { ...PRESENT, display_name: null, email: 'nameless@acme.test' },
        ]),
      }),
      at('users'),
    );

    await waitFor(() => expect(screen.getByText('nameless@acme.test')).toBeTruthy());
    // Not erased: `erased_at` is null, so the row reads as a person with a gap
    // in their profile rather than as somebody forgotten.
    expect(document.querySelector('[data-erased="true"]')).toBeNull();
    expect(screen.getByText('no name')).toBeTruthy();
  });

  it('is explained when a search finds nothing', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({ 'GET /api/v1/admin/users': page('users', []) }),
      at('users'),
    );

    await waitFor(() => expect(screen.getByText('No people')).toBeTruthy());
    expect(screen.getByText(/matches no search, having neither/i)).toBeTruthy();
  });
});

describe('the tab', () => {
  it('is in the URL, so one listing can be linked to', async () => {
    const view = renderAtRoute(<DirectoryScreen />, clientFor(), at());

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());

    fireEvent.click(document.querySelector('[data-tab="invoices"]') as HTMLElement);

    await waitFor(() => expect(view.location()).toContain('tab=invoices'));
    await waitFor(() => expect(screen.getByTestId('invoice-number')).toBeTruthy());
  });

  it('falls back to tenants when the URL names one that does not exist', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('nonsense'));

    // A bad link opens the page rather than breaking it.
    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());
    expect(document.querySelector('[data-tab="tenants"]')?.getAttribute('aria-current')).toBe('page');
  });

  it('clears the filter, because it means something different on each listing', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/admin/tenants': page('tenants', [TENANT]),
      'GET /api/v1/admin/users': page('users', [PRESENT]),
    });

    renderAtRoute(<DirectoryScreen />, client, at());

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'acme' } });

    await waitFor(() =>
      expect(
        requests.some((r) => (r.query as { search?: string } | undefined)?.search === 'acme'),
      ).toBe(true),
    );

    fireEvent.click(document.querySelector('[data-tab="users"]') as HTMLElement);

    // "acme" as a tenant slug and "acme" as a person's name are different
    // questions; carrying it over would answer the wrong one silently.
    await waitFor(() => expect(screen.getByText('Ada')).toBeTruthy());
    const userSearches = requests
      .filter((r) => r.path === '/api/v1/admin/users')
      .map((r) => (r.query as { search?: string } | undefined)?.search);

    expect(userSearches).not.toContain('acme');
  });
});

describe('a draft invoice', () => {
  it('says it has no number rather than inventing one', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('invoices'));

    await waitFor(() =>
      expect(screen.getByTestId('invoice-number').textContent).toBe('no number yet'),
    );
    // Money from minor units, as everywhere else.
    expect(document.querySelector('[data-minor-units="3480"]')).not.toBeNull();
  });
});

/**
 * Who holds what, in the console (2026-09-26).
 *
 * This list named the organisation and nothing else, which described the world
 * as it was before ADR-055: since then everything the tenant surface sells is
 * a **seat held by one person**, so three seats in Acme read as three
 * identical rows and the operator could not tell whose was whose.
 */
describe('a subscription in the console', () => {
  const seat = {
    id: '55555555-5555-4555-8555-555555555555',
    tenant_id: TENANT.id,
    tenant_name: 'Acme Ltd',
    product_code: 'plan',
    status: 'ACTIVE',
    subscriber_kind: 'USER',
    owner_user_id: '66666666-6666-4666-8666-666666666666',
    holder_name: 'Ada Lovelace',
    holder_email: 'ada@acme.test',
    offer_code: 'pro-monthly',
    offer_version: 1,
    price_minor_units: 2900,
    currency: 'EUR',
    started_at: '2026-09-01T00:00:00Z',
    current_period_end: '2026-10-01T00:00:00Z',
    cancel_at_period_end: false,
    term_ends_at: null,
    commitment_ends_at: null,
  };

  it('names the person who holds the seat, beside the organisation', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({ 'GET /api/v1/admin/subscriptions': page('subscriptions', [seat]) }),
      at('subscriptions'),
    );

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());

    expect(document.querySelector('[data-holder="person"]')?.textContent).toContain('Ada Lovelace');
    // And which product, because the platform is multi-product and this said
    // only the offer code.
    expect(screen.getByText(/plan · pro-monthly/)).toBeTruthy();
  });

  it('falls back to the address when the person has no display name', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/subscriptions': page('subscriptions', [{ ...seat, holder_name: null }]),
      }),
      at('subscriptions'),
    );

    await waitFor(() =>
      expect(document.querySelector('[data-holder="person"]')?.textContent).toContain('ada@acme.test'),
    );
  });

  it('says the organisation for a subscription that names nobody', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/subscriptions': page('subscriptions', [
          { ...seat, subscriber_kind: 'TENANT', owner_user_id: null, holder_name: null, holder_email: null },
        ]),
      }),
      at('subscriptions'),
    );

    // Said, not borrowed: naming Acme as the holder of Acme's own
    // subscription would read as a person called Acme.
    await waitFor(() => expect(document.querySelector('[data-holder="tenant"]')).not.toBeNull());
    expect(document.querySelector('[data-holder="person"]')).toBeNull();
  });

  it('says nobody rather than attributing a row with no owner recorded', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/subscriptions': page('subscriptions', [
          { ...seat, owner_user_id: null, holder_name: null, holder_email: null },
        ]),
      }),
      at('subscriptions'),
    );

    await waitFor(() => expect(document.querySelector('[data-holder="unrecorded"]')).not.toBeNull());
  });
});

/**
 * An invoice a tenant issued names two parties that are not the tenant's row
 * (ADR-054, ADR-055) — Acme sold the seat, one person bought it.
 */
describe('an invoice in the console', () => {
  const issuedByAcme = {
    ...INVOICE,
    number: '2026-000001',
    status: 'PAID',
    product_code: 'plan',
    issuer_tenant_id: TENANT.id,
    supplier_name: 'Acme Ltd',
    customer_name: 'Ada Lovelace',
    customer_email: 'ada@acme.test',
  };

  it('shows the supplier and the customer the document carries', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({ 'GET /api/v1/admin/invoices': page('invoices', [issuedByAcme]) }),
      at('invoices'),
    );

    await waitFor(() => expect(document.querySelector('[data-parties]')).not.toBeNull());

    const parties = document.querySelector('[data-parties]')?.textContent ?? '';

    expect(parties).toContain('Acme Ltd');
    expect(parties).toContain('Ada Lovelace');
  });

  it('says nothing extra on the platform\'s own invoice', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/invoices': page('invoices', [
          { ...issuedByAcme, issuer_tenant_id: null, supplier_name: 'Atlas SAS', customer_name: 'Acme Ltd' },
        ]),
      }),
      at('invoices'),
    );

    await waitFor(() => expect(screen.getByTestId('invoice-number')).toBeTruthy());
    // The customer is the organisation, whose name is already on the row:
    // repeating it would be noise on every line.
    expect(document.querySelector('[data-parties]')).toBeNull();
  });
});

/**
 * Whether the money arrived (2026-09-26).
 *
 * The console could read every invoice ever raised and not one payment against
 * one of them, so *"did this customer actually pay?"* had no screen. These
 * tests are about what the row **says**: an amount with no name and no
 * document beside it is the same row twelve times over.
 */
describe('a payment in the console', () => {
  it('asks the platform-wide payments endpoint, paged, with the status typed', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/admin/payments': page('payments', [PAYMENT]),
    });

    renderAtRoute(<DirectoryScreen />, client, at('payments'));

    await waitFor(() => expect(screen.getByTestId('payment-invoice-number')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'FAILED' } });

    // What the request *does*, not what a constant holds: the path the
    // contract declares, and a bounded page — a payments list grows faster
    // than any other admin list.
    await waitFor(() =>
      expect(
        requests.some(
          (r) =>
            r.path === '/api/v1/admin/payments' &&
            (r.query as { status?: string } | undefined)?.status === 'FAILED' &&
            typeof (r.query as { limit?: number } | undefined)?.limit === 'number',
        ),
      ).toBe(true),
    );
    // And nowhere else: a hand-written path would compose something like
    // /api/v1/api/v1/admin/payments and still look right in a constant.
    expect(requests.every((r) => r.path === '/api/v1/admin/payments')).toBe(true);
  });

  it('names the invoice it collects and the customer that document carries', async () => {
    renderAtRoute(<DirectoryScreen />, clientFor(), at('payments'));

    await waitFor(() =>
      expect(screen.getByTestId('payment-invoice-number').textContent).toBe('2026-000004'),
    );

    // From the invoice's snapshot, so a seat's payment names the person and
    // not the organisation — the organisation is on the row once, above.
    const whose = screen.getByTestId('payment-customer');

    expect(whose.getAttribute('data-whose')).toBe('person');
    expect(whose.textContent).toContain('Ada Lovelace');
    // Minor units all the way to the screen, converted only in Money.
    expect(document.querySelector('[data-minor-units="3480"]')).not.toBeNull();
  });

  it('says the invoice has no number while it is still a draft', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/payments': page('payments', [
          { ...PAYMENT, invoice_number: null, status: 'PENDING', succeeded_at: null },
        ]),
      }),
      at('payments'),
    );

    // Not a placeholder: a number comes from a gapless sequence at issue, and
    // a stand-in is how a hole enters one.
    await waitFor(() =>
      expect(screen.getByTestId('payment-invoice-number').textContent).toBe('no number yet'),
    );
    expect(document.querySelector('[data-settlement="open"]')).not.toBeNull();
  });

  it('tells a refusal from a receipt', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/payments': page('payments', [
          {
            ...PAYMENT,
            status: 'FAILED',
            succeeded_at: null,
            failed_at: '2026-09-21T08:00:00Z',
            failure_code: 'card_declined',
            failure_reason: 'The card was declined.',
          },
        ]),
      }),
      at('payments'),
    );

    await waitFor(() => expect(document.querySelector('[data-settlement="failed"]')).not.toBeNull());
    // One timestamp labelled "date" would report a refusal and a receipt
    // identically, which is the distinction somebody came to this screen for.
    expect(document.querySelector('[data-settlement="succeeded"]')).toBeNull();
    expect(document.querySelector('[data-payment-status="FAILED"]')?.textContent).toContain(
      'The card was declined.',
    );
  });

  it('is reachable by a link, like every other listing', async () => {
    const view = renderAtRoute(<DirectoryScreen />, clientFor(), at());

    await waitFor(() => expect(screen.getByText('Acme Ltd')).toBeTruthy());

    fireEvent.click(document.querySelector('[data-tab="payments"]') as HTMLElement);

    await waitFor(() => expect(view.location()).toContain('tab=payments'));
    await waitFor(() => expect(screen.getByTestId('payment-invoice-number')).toBeTruthy());
  });
});

describe('the counts', () => {
  it('are the counted total, not the length of the page', async () => {
    renderAtRoute(
      <DirectoryScreen />,
      clientFor({
        'GET /api/v1/admin/tenants': { data: { tenants: [TENANT], total: 412, limit: 25, offset: 0 } },
      }),
      at(),
    );

    // An operator needs to know whether they are looking at forty customers or
    // four thousand, and a short page answers that only on the last one.
    await waitFor(() =>
      expect(screen.getByTestId('directory-count').textContent).toBe('Showing 1 of 412.'),
    );
  });
});
