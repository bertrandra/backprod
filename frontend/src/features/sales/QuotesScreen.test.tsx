import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { QuotesScreen } from './QuotesScreen';

/**
 * The first irreversible action, and the fact the screen must not recompute.
 *
 * `open` is derived from the clock **by the server**. The fixtures below set it
 * deliberately against `valid_until` — a quote whose date has passed but which
 * the server still calls open, and one still in date that the server calls
 * closed — because a screen that worked expiry out for itself would disagree with
 * exactly those two, and they are the ones that matter.
 */
const SELLER = { ...SESSION, permissions: [...SESSION.permissions, 'sales.read', 'sales.manage'] };

const LAST_YEAR = '2025-01-01T00:00:00Z';
const NEXT_CENTURY = '2126-01-01T00:00:00Z';

function quote(overrides: Record<string, unknown> = {}) {
  return {
    id: 'q-1',
    status: 'SENT',
    open: true,
    offer_version_id: 'v-1',
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 580, currency: 'EUR' },
    gross: { minor_units: 3480, currency: 'EUR' },
    valid_until: NEXT_CENTURY,
    customer: {},
    sent_at: '2026-01-01T00:00:00Z',
    decided_at: null,
    created_at: '2026-01-01T00:00:00Z',
    lines: [],
    ...overrides,
  };
}

const listing = (quotes: unknown[]): Stub => ({
  data: { quotes, total: quotes.length, limit: 25, offset: 0 },
});

function clientFor(quotes: unknown[], extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SELLER },
    'GET /api/v1/sales/quotes': listing(quotes),
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<QuotesScreen />, client, { path: '/quotes' });

describe('whether a quote can be acted on', () => {
  it('follows the server’s `open`, even when the date says otherwise', async () => {
    // Date long past, server says open. A screen computing expiry itself would
    // hide the buttons here and be wrong.
    render(clientFor([quote({ valid_until: LAST_YEAR, open: true })]));

    await waitFor(() => expect(screen.getByRole('button', { name: /^accept$/i })).toBeTruthy());
    expect(screen.getByRole('button', { name: /^reject$/i })).toBeTruthy();
  });

  it('follows it the other way too', async () => {
    // Date in the future, server says closed — an accepted or rejected quote,
    // or one withdrawn. The buttons must be gone.
    render(clientFor([quote({ valid_until: NEXT_CENTURY, open: false })]));

    await waitFor(() => expect(screen.getByText(/valid until/i)).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^accept$/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /^reject$/i })).toBeNull();
  });

  it('explains a sent quote that is no longer open', async () => {
    render(clientFor([quote({ open: false, valid_until: LAST_YEAR })]));

    await waitFor(() => expect(screen.getByTestId('expired')).toBeTruthy());
  });
});

describe('rejecting', () => {
  it('takes a confirmation that says it cannot be undone', async () => {
    let rejected = 0;

    render(
      clientFor([quote()], {
        'POST /api/v1/sales/quotes/{quoteId}/reject': (): Stub => {
          rejected += 1;

          return { data: quote({ status: 'REJECTED', open: false }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^reject$/i })).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /^reject$/i }));
    expect(rejected).toBe(0);
    expect(screen.getByText(/cannot be undone/i)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /reject permanently/i }));
    await waitFor(() => expect(rejected).toBe(1));
  });

  it('can be backed out of', async () => {
    let rejected = 0;

    render(
      clientFor([quote()], {
        'POST /api/v1/sales/quotes/{quoteId}/reject': (): Stub => {
          rejected += 1;

          return { data: quote({ status: 'REJECTED' }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^reject$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^reject$/i }));
    fireEvent.click(screen.getByRole('button', { name: /keep it open/i }));

    expect(rejected).toBe(0);
    expect(screen.getByRole('button', { name: /^accept$/i })).toBeTruthy();
  });

  it('is never optimistic — the row does not change until the server answers', async () => {
    render(
      clientFor([quote()], {
        'POST /api/v1/sales/quotes/{quoteId}/reject': (): Stub => ({
          data: quote({ status: 'REJECTED', open: false }),
          // Held open, so "what is on screen while the request is in flight" is
          // observable at all.
          delayMs: 5_000,
        }),
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^reject$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^reject$/i }));
    fireEvent.click(screen.getByRole('button', { name: /reject permanently/i }));

    // Still SENT: nothing was assumed. An optimistic version would already read
    // REJECTED here, and would have to take it back if the server refused.
    await waitFor(() =>
      expect(document.querySelector('[data-quote="q-1"]')?.getAttribute('data-status')).toBe(
        'SENT',
      ),
    );
  });
});

describe('accepting', () => {
  it('produces an order and goes to it', async () => {
    const { location } = render(
      clientFor([quote()], {
        'POST /api/v1/sales/quotes/{quoteId}/accept': {
          data: {
            id: 'order-9',
            status: 'PENDING',
            quote_id: 'q-1',
            offer_version_id: 'v-1',
            subscription_id: null,
            invoice_id: null,
            net: { minor_units: 2900, currency: 'EUR' },
            vat: { minor_units: 580, currency: 'EUR' },
            gross: { minor_units: 3480, currency: 'EUR' },
            completed_at: null,
            created_at: '2026-01-01T00:00:00Z',
            lines: [],
          },
          status: 201,
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^accept$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^accept$/i }));

    // Accepting answers with an *order*, and the person accepted in order to get
    // somewhere.
    await waitFor(() => expect(location()).toContain('selected=order-9'));
  });
});

describe('the money', () => {
  it('shows the pinned version that priced the quote', async () => {
    render(clientFor([quote()]));

    await waitFor(() => expect(screen.getByText(/priced by offer version/i)).toBeTruthy());
    expect(screen.getByText('v-1')).toBeTruthy();
  });

  it('renders every amount from its own minor units', async () => {
    render(clientFor([quote()]));

    await waitFor(() => expect(document.querySelector('[data-minor-units="3480"]')).not.toBeNull());
    // Net and VAT too — nothing on screen is a sum computed here.
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
    expect(document.querySelector('[data-minor-units="580"]')).not.toBeNull();
  });

  it('shows a line’s VAT rate from its basis points', async () => {
    render(
      clientFor([
        quote({
          lines: [
            {
              position: 1,
              description: 'Pro monthly',
              quantity: 1,
              unit_price: { minor_units: 2900, currency: 'EUR' },
              discount: { minor_units: 0, currency: 'EUR' },
              net: { minor_units: 2900, currency: 'EUR' },
              vat_rate_basis_points: 550,
              vat: { minor_units: 160, currency: 'EUR' },
              gross: { minor_units: 3060, currency: 'EUR' },
              source_offer_version_id: 'v-1',
            },
          ],
        }),
      ]),
    );

    // 5.5%, with the half intact.
    await waitFor(() => expect(screen.getByText(/VAT 5\.5%/)).toBeTruthy());
  });
});
