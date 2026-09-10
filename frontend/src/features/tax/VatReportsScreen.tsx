import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useViewState } from '@/app/frame/viewState';
import { useSession } from '@/queries/session';
import {
  isClosed,
  useCloseVatPeriod,
  useVatPeriod,
  useVatPeriods,
  useVatTransactions,
  type VatDeclaration,
  type VatPeriod,
} from '@/queries/tax';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount, formatVatRate } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `tax.reports` — periods, the figures behind them, and the one action in this
 * application that cannot be undone.
 *
 * **Closing is one-way, and a database trigger is what enforces it**: *"figures
 * somebody has declared do not quietly change underneath them"*. There is no
 * reopen operation in the contract, none in `queries/tax.ts`, and no path to one
 * here. The absence is the design, the same way a published offer version has no
 * edit — so a closed period renders as a record rather than as a form with its
 * controls greyed out, because a disabled button still says "this is nearly
 * possible" and it is not.
 *
 * **A closed period reports what was declared**, not what the same query would
 * compute today. The declaration is frozen at closure and the screen reads
 * *that*; a recomputation would answer a different question from the one that
 * was filed, and would answer it differently every time a late credit note
 * landed.
 *
 * **The confirmation names what becomes impossible.** "Are you sure?" asks
 * somebody to confirm a decision using only what they already knew, which for a
 * one-way action is nothing. What they need is the list below.
 *
 * The selected period lives in `?selected=` (ui-spec.md §4.3), so the accountant
 * being asked about a quarter is sent a link to that quarter.
 */
export function VatReportsScreen() {
  const { data: session } = useSession();
  const { selected } = useViewState();
  const navigate = useNavigate();
  const periods = useVatPeriods();

  const mayManage = can(session, 'tax.manage');

  const select = (id: string | null) => {
    void navigate({
      to: '/tax/reports',
      search: id === null ? {} : { selected: id },
    });
  };

  return (
    <div className="space-y-8">
      <header className="space-y-1">
        <h1 className="text-lg font-semibold">VAT periods</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          A period is open while its figures can still move. Closing one files a declaration and
          freezes it — permanently, and by a rule the database enforces rather than this screen.
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-[20rem_1fr]">
        <section className="space-y-2">
          {periods.isPending ? (
            <SkeletonRows rows={5} />
          ) : periods.error !== null ? (
            <ErrorSurface error={periods.error} onRetry={() => void periods.refetch()} />
          ) : periods.data.length === 0 ? (
            <EmptyState
              title="No periods yet"
              description="Periods appear once there is something to declare in a jurisdiction."
            />
          ) : (
            <ul className="space-y-2">
              {periods.data.map((period) => (
                <li key={period.id}>
                  <button
                    type="button"
                    data-period={period.id}
                    aria-current={selected === period.id ? 'true' : undefined}
                    onClick={() => select(period.id)}
                    className={`w-full rounded border p-3 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-2 ${
                      selected === period.id
                        ? 'border-neutral-900 dark:border-neutral-100'
                        : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900'
                    }`}
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-medium">{period.jurisdiction}</span>
                      <StatusBadge period={period} />
                    </span>
                    <span className="mt-1 block text-xs text-neutral-600 dark:text-neutral-400">
                      {period.period_kind} · {new Date(period.starts_on).toLocaleDateString()} —{' '}
                      {new Date(period.ends_on).toLocaleDateString()}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="min-w-0">
          {selected === undefined ? (
            <EmptyState
              title="No period selected"
              description="Choose one to see its figures and, if it is closed, the declaration that was filed."
            />
          ) : (
            <PeriodDetail periodId={selected} mayManage={mayManage} />
          )}
        </section>
      </div>

      <Transactions />
    </div>
  );
}

function StatusBadge({ period }: { period: VatPeriod }) {
  return (
    <span
      data-testid="period-status"
      data-status={period.status}
      className={`rounded px-2 py-0.5 text-xs font-medium ${
        isClosed(period)
          ? 'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
          : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200'
      }`}
    >
      {isClosed(period) ? 'Closed' : 'Open'}
    </span>
  );
}

function PeriodDetail({ periodId, mayManage }: { periodId: string; mayManage: boolean }) {
  const period = useVatPeriod(periodId);

  if (period.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (period.error !== null) {
    return <ErrorSurface error={period.error} onRetry={() => void period.refetch()} />;
  }

  const { period: current, declaration, totals } = period.data;
  const closed = isClosed(current);

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="text-base font-semibold">
            {current.jurisdiction} · {new Date(current.starts_on).toLocaleDateString()} —{' '}
            {new Date(current.ends_on).toLocaleDateString()}
          </h2>
          <StatusBadge period={current} />
        </div>

        {closed && current.closed_at !== null && (
          <p data-testid="closed-at" className="text-sm text-neutral-600 dark:text-neutral-400">
            Closed on {new Date(current.closed_at).toLocaleDateString()}. The figures below are the
            ones that were declared, not a recount — a later correction belongs in a later period.
          </p>
        )}
      </header>

      {/* A closed period shows the frozen declaration; an open one shows what a
          query says today, and says so. The two are different questions. */}
      {closed && declaration !== null ? (
        <Declaration declaration={declaration} />
      ) : (
        <Totals totals={totals} />
      )}

      {closed ? (
        // No control, not a disabled one: there is no reopen operation to
        // disable, and a greyed button would suggest otherwise.
        <p
          data-testid="no-reopen"
          className="rounded border border-neutral-200 p-3 text-sm text-neutral-600 dark:border-neutral-800 dark:text-neutral-400"
        >
          This period cannot be reopened. Nothing here can change it, and neither can support —
          the database refuses the change. A figure that turns out to be wrong is corrected in a
          later period, which is what leaves an audit trail.
        </p>
      ) : (
        <CloseSection period={current} mayManage={mayManage} />
      )}
    </div>
  );
}

/**
 * The offer to close, and the confirmation that earns it.
 *
 * The button is only shown when closing could actually succeed. The backend
 * refuses a period that has not ended (`PERIOD_NOT_ENDED`), and offering the
 * action anyway would invite somebody to try a one-way operation and discover
 * the rule from an error — so the rule is stated where the button would be.
 */
function CloseSection({ period, mayManage }: { period: VatPeriod; mayManage: boolean }) {
  const close = useCloseVatPeriod();
  const [confirming, setConfirming] = useState(false);

  // The clock, read **once at mount** rather than on every render. A component
  // that called `Date.now()` while rendering would answer a different question
  // each time React happened to re-run it, which is the objection `QuotesScreen`
  // raises against recomputing a quote's `open` in the browser.
  //
  // And this comparison only *hides a control*: `PERIOD_NOT_ENDED` is the
  // backend's, and it is what actually refuses. Hiding is courtesy — the same
  // rule the nav follows — so a clock a few seconds out costs a refusal with a
  // reason, never a period closed early.
  //
  // Compared as dates, not timestamps: `ends_on` is a date, and the period is
  // over once that day is behind us.
  const [now] = useState(() => Date.now());
  const ended = new Date(`${period.ends_on}T23:59:59Z`).getTime() < now;

  if (!mayManage) {
    return (
      <p data-testid="cannot-close" className="text-sm text-neutral-600 dark:text-neutral-400">
        Closing a period needs <code>tax.manage</code>.
      </p>
    );
  }

  if (!ended) {
    return (
      <p data-testid="not-ended" className="text-sm text-neutral-600 dark:text-neutral-400">
        This period has not ended yet, so it cannot be closed. Closing freezes figures that are
        still moving, and it cannot be undone afterwards to fix that.
      </p>
    );
  }

  return (
    <div className="space-y-3 rounded border border-amber-300 p-4 dark:border-amber-800">
      <h3 className="text-sm font-semibold">Close this period</h3>

      {close.error !== null && <ErrorSurface error={close.error} />}

      {confirming ? (
        <div data-testid="close-confirmation" className="space-y-3 text-sm">
          {/* What becomes impossible — not "are you sure?". Somebody confirming
              a one-way action needs to learn something at the confirmation
              they did not know at the button. */}
          <p>Closing this period does the following, and none of it can be undone:</p>
          <ul className="list-inside list-disc text-neutral-700 dark:text-neutral-300">
            <li>the figures are frozen as a declaration and stop tracking new transactions</li>
            <li>this period can never be reopened — there is no operation that does it</li>
            <li>
              a transaction dated inside it that arrives later will not change the declared totals
            </li>
            <li>a mistake is corrected in a later period, visibly, rather than in this one</li>
          </ul>

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="danger"
              pending={close.isPending}
              onClick={() => close.mutate(period.id, { onSettled: () => setConfirming(false) })}
            >
              Close it permanently
            </Button>
            <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
              Leave it open
            </Button>
          </div>
        </div>
      ) : (
        <>
          <p className="text-sm text-neutral-600 dark:text-neutral-400">
            The period has ended. Closing it files the declaration.
          </p>
          <Button type="button" variant="danger" onClick={() => setConfirming(true)}>
            Close the period…
          </Button>
        </>
      )}
    </div>
  );
}

/** The filed declaration, exactly as it was frozen. */
function Declaration({ declaration }: { declaration: VatDeclaration }) {
  const base = { minor_units: declaration.total_base, currency: declaration.currency };
  const vat = { minor_units: declaration.total_vat, currency: declaration.currency };

  return (
    <section
      data-testid="declaration"
      className="space-y-3 rounded border border-neutral-200 p-4 text-sm dark:border-neutral-800"
    >
      <h3 className="font-semibold">Declared</h3>

      <dl className="grid gap-3 sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Taxable base</dt>
          <dd data-testid="declared-base">
            <Amount money={base} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">VAT</dt>
          <dd data-testid="declared-vat">
            <Amount money={vat} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Transactions</dt>
          <dd>{declaration.transaction_count}</dd>
        </div>
      </dl>

      <Breakdown rows={declaration.breakdown} />

      <p className="text-xs text-neutral-500">
        Declaration <code>{declaration.id}</code> · filed{' '}
        {new Date(declaration.created_at).toLocaleDateString()}
      </p>
    </section>
  );
}

/** What the period holds today. An open period has no declaration to show. */
function Totals({ totals }: { totals: Record<string, unknown> }) {
  const currency = typeof totals.currency === 'string' ? totals.currency : 'EUR';
  const base = typeof totals.total_base === 'number' ? totals.total_base : 0;
  const vat = typeof totals.total_vat === 'number' ? totals.total_vat : 0;
  const count = typeof totals.transaction_count === 'number' ? totals.transaction_count : 0;
  const currencies = Array.isArray(totals.currencies) ? totals.currencies : [];

  return (
    <section
      data-testid="totals"
      className="space-y-3 rounded border border-neutral-200 p-4 text-sm dark:border-neutral-800"
    >
      <h3 className="font-semibold">As it stands today</h3>
      <p className="text-xs text-neutral-600 dark:text-neutral-400">
        Not a declaration. These figures move while the period is open, and a transaction booked
        tomorrow changes them.
      </p>

      <dl className="grid gap-3 sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Taxable base</dt>
          <dd data-testid="running-base">
            <Amount money={{ minor_units: base, currency }} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">VAT</dt>
          <dd data-testid="running-vat">
            <Amount money={{ minor_units: vat, currency }} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Transactions</dt>
          <dd>{count}</dd>
        </div>
      </dl>

      {/* A declaration carries one currency, so a period holding two cannot be
          closed. Said here rather than discovered from a 409. */}
      {currencies.length > 1 && (
        <p data-testid="mixed-currencies" className="text-amber-800 dark:text-amber-300">
          This period holds transactions in {currencies.join(', ')}. A declaration carries one
          currency, so it cannot be closed while that is true.
        </p>
      )}

      <Breakdown rows={totals.breakdown} />
    </section>
  );
}

/**
 * The rows behind a total.
 *
 * `breakdown` is a free-form object in the contract, so every field is read
 * defensively: a missing one means "not reported" rather than zero, and a row
 * that cannot be read is skipped rather than rendered as `undefined`.
 */
function label(value: unknown, fallback: string): string {
  // Only a string is a label. `String(anything)` on a nested object renders
  // "[object Object]" into a fiscal table, which is worse than saying nothing.
  return typeof value === 'string' ? value : fallback;
}

function Breakdown({ rows }: { rows: unknown }) {
  if (!Array.isArray(rows) || rows.length === 0) {
    return null;
  }

  const readable = rows.filter(
    (row): row is Record<string, unknown> => typeof row === 'object' && row !== null,
  );

  if (readable.length === 0) {
    return null;
  }

  return (
    <div className="overflow-x-auto">
      <table data-testid="breakdown" className="w-full text-sm">
        <thead className="text-left text-xs uppercase tracking-wide text-neutral-500">
          <tr>
            <th className="py-1 pr-3">Regime</th>
            <th className="py-1 pr-3">Rate</th>
            <th className="py-1 pr-3">Base</th>
            <th className="py-1 pr-3">VAT</th>
            <th className="py-1">Count</th>
          </tr>
        </thead>
        <tbody>
          {readable.map((row, index) => {
            const currency = typeof row.currency === 'string' ? row.currency : 'EUR';
            const rate = typeof row.rate === 'number' ? row.rate : null;

            return (
              <tr
                key={`${label(row.regime, 'unknown')}-${String(rate)}-${currency}-${index}`}
                data-breakdown-row={label(row.regime, 'unknown')}
                className="border-t border-neutral-200 dark:border-neutral-800"
              >
                <td className="py-1.5 pr-3">{label(row.regime, '—')}</td>
                <td className="py-1.5 pr-3">{rate === null ? '—' : formatVatRate(rate)}</td>
                <td className="py-1.5 pr-3">
                  {typeof row.base === 'number' ? (
                    <Amount money={{ minor_units: row.base, currency }} />
                  ) : (
                    '—'
                  )}
                </td>
                <td className="py-1.5 pr-3">
                  {typeof row.vat === 'number' ? (
                    <Amount money={{ minor_units: row.vat, currency }} />
                  ) : (
                    '—'
                  )}
                </td>
                <td className="py-1.5">{typeof row.count === 'number' ? row.count : '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

/**
 * The transactions themselves.
 *
 * One row per taxable event, each carrying the rule that decided it — which is
 * what makes a declared total defensible line by line rather than as a lump.
 *
 * The endpoint pages every transaction rather than those of a period, and the
 * heading says so: implying this list were the selected period's would be a
 * quiet lie, and somebody reconciling a declaration against it would be
 * comparing the wrong two numbers.
 */
function Transactions() {
  const transactions = useVatTransactions();

  return (
    <section className="space-y-3 border-t border-neutral-200 pt-6 dark:border-neutral-800">
      <h2 className="text-base font-semibold">VAT transactions</h2>
      <p className="text-sm text-neutral-600 dark:text-neutral-400">
        Every taxable event, newest first — across all periods, not only the one selected above.
        Each carries the rule that decided its regime.
      </p>

      {transactions.isPending ? (
        <SkeletonRows rows={5} />
      ) : transactions.error !== null ? (
        <ErrorSurface error={transactions.error} onRetry={() => void transactions.refetch()} />
      ) : transactions.data.transactions.length === 0 ? (
        <EmptyState
          title="No VAT transactions"
          description="One is booked whenever an invoice or a credit note is issued."
        />
      ) : (
        <>
          <p data-testid="transaction-count" className="text-xs text-neutral-500">
            Showing {transactions.data.transactions.length} of {transactions.data.total}.
          </p>

          <ul className="space-y-2">
            {transactions.data.transactions.map((transaction) => (
              <li
                key={transaction.id}
                data-transaction={transaction.id}
                className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{transaction.country}</span>
                  <span
                    data-testid="transaction-regime"
                    className="rounded bg-neutral-200 px-1.5 py-0.5 text-xs dark:bg-neutral-800"
                  >
                    {transaction.vat_regime}
                  </span>
                  <span className="text-xs text-neutral-600 dark:text-neutral-400">
                    {formatVatRate(transaction.vat_rate)} · {transaction.supply_type}
                  </span>
                  <span className="ml-auto">
                    <Amount
                      money={{
                        minor_units: transaction.taxable_base,
                        currency: transaction.currency,
                      }}
                    />{' '}
                    +{' '}
                    <Amount
                      money={{
                        minor_units: transaction.vat_amount,
                        currency: transaction.currency,
                      }}
                      className="font-medium"
                    />
                  </span>
                </div>

                <p className="mt-1 text-xs text-neutral-500">
                  {new Date(transaction.transaction_date).toLocaleDateString()} ·{' '}
                  {transaction.invoice_id === null
                    ? `credit note ${transaction.credit_note_id ?? '—'}`
                    : `invoice ${transaction.invoice_id}`}{' '}
                  · read as {transaction.customer_tax_status} · rule{' '}
                  <code>{transaction.rule_id}</code>
                </p>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
