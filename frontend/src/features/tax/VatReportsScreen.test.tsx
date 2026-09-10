import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub, type Stubs } from '@/test-utils';

import { VatReportsScreen } from './VatReportsScreen';

/**
 * Closing a VAT period is the one action in this application that cannot be
 * undone, and these tests are about the two ways a screen can betray that.
 *
 * The first is offering a way back. There is no reopen operation in the
 * contract, so the test looks for one *by name* across the whole rendered
 * screen rather than checking that a particular button is absent — a future
 * "Reopen" added anywhere would fail it.
 *
 * The second is quietly recomputing. A closed period must report **what was
 * declared**, so the fixtures below deliberately make `totals` disagree with
 * `declaration`: a screen that recounted would show the running figures, and
 * only a screen reading the frozen declaration shows the declared ones. Both
 * numbers are present in the response, so nothing about the assertion depends
 * on the stub being incomplete.
 */
const READER = { ...SESSION, permissions: [...SESSION.permissions, 'tax.read'] };
const MANAGER = { ...READER, permissions: [...READER.permissions, 'tax.manage'] };

const DAY = 86_400_000;
const iso = (offsetDays: number): string =>
  new Date(Date.now() + offsetDays * DAY).toISOString().slice(0, 10);

/** Over and done with: a period that closing would be allowed for. */
const ENDED = {
  id: 'per-ended',
  jurisdiction: 'FR',
  period_kind: 'QUARTER',
  starts_on: iso(-120),
  ends_on: iso(-30),
  status: 'OPEN',
  closed_at: null,
};

/** Still running, so the backend refuses to close it (`PERIOD_NOT_ENDED`). */
const RUNNING = { ...ENDED, id: 'per-running', starts_on: iso(-30), ends_on: iso(30) };

const CLOSED = {
  ...ENDED,
  id: 'per-closed',
  status: 'CLOSED',
  closed_at: '2026-04-02T08:00:00Z',
};

/** What the declaration froze. */
const DECLARATION = {
  id: 'dec-1',
  period_id: CLOSED.id,
  currency: 'EUR',
  total_base: 100_000,
  total_vat: 20_000,
  transaction_count: 4,
  breakdown: [
    { regime: 'STANDARD', rate: 2000, currency: 'EUR', base: 100_000, vat: 20_000, count: 4 },
  ],
};

/**
 * What the same query says *now* — deliberately different.
 *
 * A late credit note landed after closure. The declared figures must not move,
 * and these numbers are what a screen that recounted would show instead.
 */
const RECOUNTED = {
  currency: 'EUR',
  currencies: ['EUR'],
  total_base: 90_000,
  total_vat: 18_000,
  transaction_count: 5,
  breakdown: [
    { regime: 'STANDARD', rate: 2000, currency: 'EUR', base: 90_000, vat: 18_000, count: 5 },
  ],
};

const RUNNING_TOTALS = {
  currency: 'EUR',
  currencies: ['EUR'],
  total_base: 40_000,
  total_vat: 8_000,
  transaction_count: 2,
  breakdown: [
    { regime: 'STANDARD', rate: 2000, currency: 'EUR', base: 40_000, vat: 8_000, count: 2 },
  ],
};

const TRANSACTION = {
  id: 'vtx-1',
  invoice_id: 'inv-1',
  credit_note_id: null,
  country: 'FR',
  customer_tax_number: null,
  customer_tax_status: 'PRIVATE_INDIVIDUAL',
  supply_type: 'SERVICES',
  taxable_base: 12_900,
  vat_rate: 2000,
  vat_amount: 2_580,
  currency: 'EUR',
  vat_regime: 'STANDARD',
  rule_id: 'fr.b2c.standard',
  reverse_charge: false,
  transaction_date: '2026-03-04T09:00:00Z',
};

function clientFor(
  periods: readonly unknown[],
  detail: Stubs = {},
  session: unknown = MANAGER,
  extra: Stubs = {},
) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/tax/reports': { data: { periods } },
    'GET /api/v1/tax/transactions': {
      data: { transactions: [TRANSACTION], total: 1, limit: 25, offset: 0 },
    },
    ...detail,
    ...extra,
  });
}

const at = (id: string) => ({ path: '/tax/reports', initial: `/tax/reports?selected=${id}` });

describe('a closed period', () => {
  const closedDetail: Stubs = {
    'GET /api/v1/tax/reports/{periodId}': {
      data: { period: CLOSED, totals: RECOUNTED, declaration: DECLARATION },
    },
  };

  it('is visibly closed', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([CLOSED], closedDetail), at(CLOSED.id));

    await waitFor(() =>
      expect(
        document.querySelector('[data-testid="period-status"][data-status="CLOSED"]'),
      ).not.toBeNull(),
    );
    expect(screen.getByTestId('closed-at').textContent).toMatch(/Closed on/);
  });

  it('offers no path to reopen', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([CLOSED], closedDetail), at(CLOSED.id));

    await waitFor(() => expect(screen.getByTestId('no-reopen')).toBeTruthy());

    // Not "the button is disabled": there is no operation to disable, and a
    // greyed control would tell somebody this is nearly possible.
    // Matched by what a control would be *called*, not by a testid, so a
    // "Reopen" added anywhere on this screen fails this. The period buttons in
    // the list are deliberately not caught: their accessible name contains the
    // word "Closed" because that is the status they display.
    expect(screen.queryByRole('button', { name: /reopen|re-open|unlock/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /close the period|close it/i })).toBeNull();
    expect(screen.getByTestId('no-reopen').textContent).toMatch(/cannot be reopened/i);
    expect(screen.getByTestId('no-reopen').textContent).toMatch(/corrected in a later period/i);
  });

  it('reports what was declared, not what a recount says today', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([CLOSED], closedDetail), at(CLOSED.id));

    await waitFor(() => expect(screen.getByTestId('declaration')).toBeTruthy());

    // The declared figures, not the ones in `totals` — both are in the response.
    expect(screen.getByTestId('declared-base').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('100000');
    expect(screen.getByTestId('declared-vat').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('20000');
    expect(document.querySelector('[data-minor-units="90000"]')).toBeNull();
    expect(screen.queryByTestId('totals')).toBeNull();
  });
});

describe('an open period', () => {
  const runningDetail: Stubs = {
    'GET /api/v1/tax/reports/{periodId}': {
      data: { period: RUNNING, totals: RUNNING_TOTALS, declaration: null },
    },
  };

  it('shows running figures and says they are not a declaration', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([RUNNING], runningDetail), at(RUNNING.id));

    await waitFor(() => expect(screen.getByTestId('totals')).toBeTruthy());
    expect(screen.getByTestId('totals').textContent).toMatch(/not a declaration/i);
    expect(screen.queryByTestId('declaration')).toBeNull();
  });

  it('does not offer to close one that has not ended, and says why', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([RUNNING], runningDetail), at(RUNNING.id));

    await waitFor(() => expect(screen.getByTestId('not-ended')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /close/i })).toBeNull();
    expect(screen.getByTestId('not-ended').textContent).toMatch(/cannot be undone/i);
  });

  it('warns that a period holding two currencies cannot be declared', async () => {
    renderAtRoute(
      <VatReportsScreen />,
      clientFor([ENDED], {
        'GET /api/v1/tax/reports/{periodId}': {
          data: {
            period: ENDED,
            totals: { ...RUNNING_TOTALS, currencies: ['EUR', 'CHF'] },
            declaration: null,
          },
        },
      }),
      at(ENDED.id),
    );

    await waitFor(() => expect(screen.getByTestId('mixed-currencies')).toBeTruthy());
    expect(screen.getByTestId('mixed-currencies').textContent).toMatch(/EUR, CHF/);
  });
});

describe('closing a period', () => {
  const endedDetail: Stubs = {
    'GET /api/v1/tax/reports/{periodId}': {
      data: { period: ENDED, totals: RUNNING_TOTALS, declaration: null },
    },
  };

  it('states what becomes impossible rather than asking for confirmation', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([ENDED], endedDetail), at(ENDED.id));

    await waitFor(() => expect(screen.getByRole('button', { name: /Close the period/ })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Close the period/ }));

    const confirmation = screen.getByTestId('close-confirmation');

    // "Are you sure?" asks somebody to confirm using only what they already
    // knew, which for a one-way action is nothing.
    expect(confirmation.textContent).not.toMatch(/are you sure/i);
    expect(confirmation.textContent).toMatch(/none of it can be undone/i);
    expect(confirmation.textContent).toMatch(/can never be reopened/i);
    expect(confirmation.textContent).toMatch(/will not change the declared totals/i);
    expect(confirmation.textContent).toMatch(/corrected in a later period/i);
  });

  it('can be abandoned', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([ENDED], endedDetail), at(ENDED.id));

    await waitFor(() => expect(screen.getByRole('button', { name: /Close the period/ })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Close the period/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Leave it open' }));

    expect(screen.queryByTestId('close-confirmation')).toBeNull();
  });

  it('shows the declaration only once the server has filed it', async () => {
    let asked = 0;

    // The second read answers CLOSED: closing invalidates the period, and a
    // screen showing the declaration afterwards refetched rather than assuming.
    const period = (): Stub => {
      asked += 1;

      return asked === 1
        ? { data: { period: ENDED, totals: RUNNING_TOTALS, declaration: null } }
        : {
            data: {
              period: { ...ENDED, status: 'CLOSED', closed_at: new Date().toISOString() },
              totals: RECOUNTED,
              declaration: { ...DECLARATION, period_id: ENDED.id },
            },
          };
    };

    renderAtRoute(
      <VatReportsScreen />,
      clientFor([ENDED], { 'GET /api/v1/tax/reports/{periodId}': period }, MANAGER, {
        'POST /api/v1/tax/reports/{periodId}/close': {
          data: { declaration: { ...DECLARATION, period_id: ENDED.id } },
          // Held back, so the assertion below is about the moment the request
          // is in flight rather than about a race the refetch already won.
          delayMs: 40,
        },
      }),
      at(ENDED.id),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /Close the period/ })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Close the period/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Close it permanently' }));

    // While it is in flight nothing claims the period is closed: the figures
    // are the server's to compute and this client cannot know them.
    expect(screen.queryByTestId('declaration')).toBeNull();

    await waitFor(() => expect(screen.getByTestId('declaration')).toBeTruthy());
    expect(screen.getByTestId('no-reopen')).toBeTruthy();
    expect(screen.getByTestId('declared-base').querySelector('[data-minor-units]')?.getAttribute('data-minor-units')).toBe('100000');
  });

  it('shows the reason a refusal gives, and leaves the period open', async () => {
    renderAtRoute(
      <VatReportsScreen />,
      clientFor([ENDED], endedDetail, MANAGER, {
        'POST /api/v1/tax/reports/{periodId}/close': {
          status: 409,
          error: {
            error: {
              code: 'PERIOD_HAS_MIXED_CURRENCIES',
              message: 'This period holds transactions in more than one currency.',
              details: { currencies: 'EUR, CHF' },
              request_id: 'req-9',
            },
          },
        },
      }),
      at(ENDED.id),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /Close the period/ })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Close the period/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Close it permanently' }));

    await waitFor(() =>
      expect(screen.getByRole('alert').textContent).toMatch(/more than one currency/i),
    );
    // Still open, and still closeable once the currencies are sorted out.
    expect(screen.queryByTestId('declaration')).toBeNull();
    expect(screen.getByRole('button', { name: /Close the period/ })).toBeTruthy();
  });

  it('is not offered without tax.manage', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([ENDED], endedDetail, READER), at(ENDED.id));

    await waitFor(() => expect(screen.getByTestId('cannot-close')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /Close the period/ })).toBeNull();
  });
});

describe('the selected period', () => {
  it('is in the URL, so a quarter can be linked to', async () => {
    const view = renderAtRoute(
      <VatReportsScreen />,
      clientFor([ENDED, CLOSED], {
        'GET /api/v1/tax/reports/{periodId}': {
          data: { period: CLOSED, totals: RECOUNTED, declaration: DECLARATION },
        },
      }),
      { path: '/tax/reports' },
    );

    // Awaited: the router mounts after the first render, so the screen is not
    // in the document synchronously.
    await waitFor(() => expect(screen.getByText('No period selected')).toBeTruthy());

    // Waited for: the list arrives after the first render, and clicking a null
    // would fail on the query rather than on the navigation being asserted.
    await waitFor(() =>
      expect(document.querySelector(`[data-period="${CLOSED.id}"]`)).not.toBeNull(),
    );
    fireEvent.click(document.querySelector(`[data-period="${CLOSED.id}"]`) as HTMLElement);

    await waitFor(() => expect(view.location()).toContain(`selected=${CLOSED.id}`));

    // And the URL is what the detail reads: the period arrives because the
    // route changed, not because the click handed it to a component.
    await waitFor(() => expect(screen.getByTestId('declaration')).toBeTruthy());
  });
});

describe('the transactions', () => {
  it('say they span every period, not the one selected', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([ENDED]), { path: '/tax/reports' });

    await waitFor(() => expect(screen.getByTestId('transaction-count')).toBeTruthy());
    // Implying the list were the selected period's would have somebody
    // reconciling a declaration against the wrong two numbers.
    expect(screen.getByText(/across all periods, not only the one selected/i)).toBeTruthy();
  });

  it('carry the rule that decided each regime', async () => {
    renderAtRoute(<VatReportsScreen />, clientFor([ENDED]), { path: '/tax/reports' });

    await waitFor(() => expect(screen.getByTestId('transaction-regime').textContent).toBe('STANDARD'));
    expect(screen.getByText('fr.b2c.standard')).toBeTruthy();
    expect(screen.getByText(/20% · SERVICES/)).toBeTruthy();
  });
});
