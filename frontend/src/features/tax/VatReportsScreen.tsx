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
import { panel, pill } from '@/ui/tone';
import { Table, TBody, Td, Th, THead, TR } from '@/ui/Table';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

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
      <PageHeader
        title={t("VAT periods")}
        description={t("A period is open while its figures can still move. Closing one files a declaration and freezes it — permanently, and by a rule the database enforces rather than this screen.")}
      />

      <div className="grid gap-8 lg:grid-cols-[20rem_1fr]">
        <section className="space-y-2">
          {periods.isPending ? (
            <SkeletonRows rows={5} />
          ) : periods.error !== null ? (
            <ErrorSurface error={periods.error} onRetry={() => void periods.refetch()} />
          ) : periods.data.length === 0 ? (
            <EmptyState
              title={t("No periods yet")}
              description={t("Periods appear once there is something to declare in a jurisdiction.")}
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
                        ? 'border-ink'
                        : 'border-line hover:bg-canvas dark:hover:bg-inverse'
                    }`}
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-medium">{period.jurisdiction}</span>
                      <StatusBadge period={period} />
                    </span>
                    <span className="mt-1 block text-xs text-muted">
                      {period.period_kind} · {new Date(period.starts_on).toLocaleDateString(currentLocale())} —{' '}
                      {new Date(period.ends_on).toLocaleDateString(currentLocale())}
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
              title={t("No period selected")}
              description={t("Choose one to see its figures and, if it is closed, the declaration that was filed.")}
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
      className={pill(isClosed(period) ? 'neutral' : 'success')}
    >
      {isClosed(period) ? t("Closed") : t("Open")}
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
          <h2 className="text-xl font-semibold">
            {current.jurisdiction} · {new Date(current.starts_on).toLocaleDateString(currentLocale())} —{' '}
            {new Date(current.ends_on).toLocaleDateString(currentLocale())}
          </h2>
          <StatusBadge period={current} />
        </div>

        {closed && current.closed_at !== null && (
          <p data-testid="closed-at" className="text-sm text-muted">
            {t("Closed on")}{' '}{new Date(current.closed_at).toLocaleDateString(currentLocale())}{t(". The figures below are the ones that were declared, not a recount — a later correction belongs in a later period.")}</p>
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
          className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm text-muted"
        >
          {t("This period cannot be reopened. Nothing here can change it, and neither can support — the database refuses the change. A figure that turns out to be wrong is corrected in a later period, which is what leaves an audit trail.")}</p>
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
      <p data-testid="cannot-close" className="text-sm text-muted">
        {t("Closing a period needs")}{' '}<code>{'tax.manage'}</code>.
      </p>
    );
  }

  if (!ended) {
    return (
      <p data-testid="not-ended" className="text-sm text-muted">
        {t("This period has not ended yet, so it cannot be closed. Closing freezes figures that are still moving, and it cannot be undone afterwards to fix that.")}</p>
    );
  }

  return (
    <div className={`${panel('warning')} space-y-3`}>
      <h3 className="text-sm font-semibold">{t("Close this period")}</h3>

      {close.error !== null && <ErrorSurface error={close.error} />}

      {confirming ? (
        <div data-testid="close-confirmation" className="space-y-3 text-sm">
          {/* What becomes impossible — not "are you sure?". Somebody confirming
              a one-way action needs to learn something at the confirmation
              they did not know at the button. */}
          <p>{t("Closing this period does the following, and none of it can be undone:")}</p>
          <ul className="list-inside list-disc text-muted">
            <li>{t("the figures are frozen as a declaration and stop tracking new transactions")}</li>
            <li>{t("this period can never be reopened — there is no operation that does it")}</li>
            <li>
              {t("a transaction dated inside it that arrives later will not change the declared totals")}</li>
            <li>{t("a mistake is corrected in a later period, visibly, rather than in this one")}</li>
          </ul>

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="danger"
              pending={close.isPending}
              onClick={() => close.mutate(period.id, { onSettled: () => setConfirming(false) })}
            >
              {t("Close it permanently")}</Button>
            <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
              {t("Leave it open")}</Button>
          </div>
        </div>
      ) : (
        <>
          <p className="text-sm text-muted">
            {t("The period has ended. Closing it files the declaration.")}</p>
          <Button type="button" variant="danger" onClick={() => setConfirming(true)}>
            {t("Close the period…")}</Button>
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
      className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <h3 className="font-semibold">{t("Declared")}</h3>

      <dl className="grid gap-3 sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Taxable base")}</dt>
          <dd data-testid="declared-base">
            <Amount money={base} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("VAT")}</dt>
          <dd data-testid="declared-vat">
            <Amount money={vat} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Transactions")}</dt>
          <dd>{declaration.transaction_count}</dd>
        </div>
      </dl>

      <Breakdown rows={declaration.breakdown} />

      <p className="text-xs text-subtle">
        {t("Declaration")}{' '}<code>{declaration.id}</code> {t("· filed")}{' '}
        {new Date(declaration.created_at).toLocaleDateString(currentLocale())}
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
      className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <h3 className="font-semibold">{t("As it stands today")}</h3>
      <p className="text-xs text-muted">
        {t("Not a declaration. These figures move while the period is open, and a transaction booked tomorrow changes them.")}</p>

      <dl className="grid gap-3 sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Taxable base")}</dt>
          <dd data-testid="running-base">
            <Amount money={{ minor_units: base, currency }} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("VAT")}</dt>
          <dd data-testid="running-vat">
            <Amount money={{ minor_units: vat, currency }} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Transactions")}</dt>
          <dd>{count}</dd>
        </div>
      </dl>

      {/* A declaration carries one currency, so a period holding two cannot be
          closed. Said here rather than discovered from a 409. */}
      {currencies.length > 1 && (
        <p data-testid="mixed-currencies" className="text-warning">
          {t("This period holds transactions in")}{' '}{currencies.join(', ')}{t(". A declaration carries one currency, so it cannot be closed while that is true.")}</p>
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
    <Table caption={t("VAT declared, broken down by regime and rate")}>
      <THead>
        <Th>{t("Regime")}</Th>
        <Th numeric>{t("Rate")}</Th>
        <Th numeric>{t("Base")}</Th>
        <Th numeric>{t("VAT")}</Th>
        <Th numeric>{t("Count")}</Th>
      </THead>

      <TBody>
        {readable.map((row, index) => {
          const currency = typeof row.currency === 'string' ? row.currency : 'EUR';
          const rate = typeof row.rate === 'number' ? row.rate : null;

          return (
            <TR
              key={`${label(row.regime, 'unknown')}-${String(rate)}-${currency}-${index}`}
              data-breakdown-row={label(row.regime, 'unknown')}
            >
              <Td className="font-medium">{label(row.regime, '—')}</Td>
              <Td numeric>{rate === null ? '—' : formatVatRate(rate)}</Td>
              <Td numeric>
                {typeof row.base === 'number' ? (
                  <Amount money={{ minor_units: row.base, currency }} />
                ) : (
                  '—'
                )}
              </Td>
              <Td numeric className="font-medium">
                {typeof row.vat === 'number' ? (
                  <Amount money={{ minor_units: row.vat, currency }} />
                ) : (
                  '—'
                )}
              </Td>
              <Td numeric className="text-muted">
                {typeof row.count === 'number' ? row.count : '—'}
              </Td>
            </TR>
          );
        })}
      </TBody>
    </Table>
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
 *
 * **Across every product the organisation holds** (2026-09-17,
 * docs/tenant-roots.md §2.7): a customer's VAT picture is one, so the
 * product in the bar does not narrow this list — each row names its own.
 */
function Transactions() {
  const transactions = useVatTransactions();

  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">{t("VAT transactions")}</h2>
      <p className="text-sm text-muted">
        {t("Every taxable event, newest first — across all periods, not only the one selected above, and across every product this organisation holds. Each carries the rule that decided its regime.")}</p>

      {transactions.isPending ? (
        <SkeletonRows rows={5} />
      ) : transactions.error !== null ? (
        <ErrorSurface error={transactions.error} onRetry={() => void transactions.refetch()} />
      ) : transactions.data.transactions.length === 0 ? (
        <EmptyState
          title={t("No VAT transactions")}
          description={t("One is booked whenever an invoice or a credit note is issued.")}
        />
      ) : (
        <>
          <p data-testid="transaction-count" className="text-xs text-subtle">
            {t("Showing")}{' '}{transactions.data.transactions.length} {t("of")}{' '}{transactions.data.total}.
          </p>

          <ul className="space-y-2">
            {transactions.data.transactions.map((transaction) => (
              <li
                key={transaction.id}
                data-transaction={transaction.id}
                className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{transaction.country}</span>
                  <span
                    data-testid="transaction-regime"
                    className="rounded bg-well px-1.5 py-0.5 text-xs"
                  >
                    {transaction.vat_regime}
                  </span>
                  <span className="text-xs text-muted">
                    {formatVatRate(transaction.vat_rate)} · {transaction.supply_type}
                    {transaction.product !== null && (
                      <>
                        {' · '}
                        <code data-testid="transaction-product">{transaction.product}</code>
                      </>
                    )}
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

                <p className="mt-1 text-xs text-subtle">
                  {new Date(transaction.transaction_date).toLocaleDateString(currentLocale())} ·{' '}
                  {transaction.invoice_id === null
                    ? t("credit note {value}", { value: transaction.credit_note_id ?? '—' })
                    : `invoice ${transaction.invoice_id}`}{' '}
                  {t("· read as")}{' '}{transaction.customer_tax_status} {t("· rule")}{' '}
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
