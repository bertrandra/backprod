import { useState } from 'react';

import { useMetrics } from '@/queries/admin';
import { useProducts } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `console.admin.metrics` — the three figures §25.2 starts with.
 *
 * **One product at a time, and there is no "all products".** The contract
 * requires `product_id` and this screen does not work around that: turnover
 * summed across products with different currencies and different catalogues is a
 * number nobody could defend, and offering it would invite somebody to quote it.
 *
 * **A month still moving is labelled as such.** `closed` distinguishes a settled
 * figure from this morning's, and without it a reader cannot tell which they are
 * looking at — the same figure means two different things on the 2nd and the
 * 31st.
 *
 * **Credits sit beside turnover, never subtracted from it.** The presenter is
 * explicit about that, and so is this screen: netting them off would produce a
 * third number that matches neither the ledger nor the invoices.
 *
 * **Renewal distinguishes "nothing came up" from "nothing renewed."** `measured`
 * is false when no subscription was due, and a bare 0% would report an unbuilt
 * feature as total churn — nothing auto-renews yet, because the notice deadlines
 * are unconfirmed (R11).
 */
export function MetricsScreen() {
  const products = useProducts();
  const [productId, setProductId] = useState<string | null>(null);
  const [months, setMonths] = useState(12);

  // The first product, until somebody chooses. Deliberately not "all": there is
  // no such answer, and defaulting to one is honest about which it is.
  const chosen = productId ?? products.data?.[0]?.id ?? null;
  const metrics = useMetrics(chosen, months);

  return (
    <div className="space-y-8">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Metrics</h1>
        <p className="text-sm text-muted">
          Turnover, the offers that earned it, and renewal — for one product. There is no combined
          figure across products: different currencies and different catalogues do not add up.
        </p>
      </header>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-64">
          <Field id="product" label="Product">
            <select
              id="product"
              className={inputClass()}
              value={chosen ?? ''}
              onChange={(event) => setProductId(event.target.value)}
            >
              {(products.data ?? []).map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <div className="w-40">
          <Field id="months" label="Months" hint="1 to 60.">
            <input
              id="months"
              type="number"
              min={1}
              max={60}
              className={inputClass()}
              value={months}
              onChange={(event) => {
                const next = Number(event.target.value);

                if (Number.isInteger(next) && next >= 1 && next <= 60) {
                  setMonths(next);
                }
              }}
            />
          </Field>
        </div>
      </div>

      {products.error !== null ? (
        <ErrorSurface error={products.error} onRetry={() => void products.refetch()} />
      ) : chosen === null ? (
        <EmptyState title="No products" description="Nothing is registered to report on." />
      ) : metrics.isPending ? (
        <SkeletonRows rows={8} />
      ) : metrics.error !== null ? (
        <ErrorSurface error={metrics.error} onRetry={() => void metrics.refetch()} />
      ) : (
        <>
          <Turnover rows={metrics.data.turnover} />
          <TopOffers offers={metrics.data.top_offers} />
          <Renewal rows={metrics.data.renewal} />
        </>
      )}
    </div>
  );
}

/** A number that is present, or nothing. Never a zero standing in for absent. */
function integer(value: unknown): number | null {
  return typeof value === 'number' ? value : null;
}

function text(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

function Turnover({ rows }: { rows: readonly Record<string, unknown>[] }) {
  if (rows.length === 0) {
    return (
      <EmptyState title="No turnover" description="Nothing has been invoiced in this window." />
    );
  }

  return (
    <section className="space-y-3">
      <h2 className="text-xl font-semibold">Turnover</h2>
      <p className="text-sm text-muted">
        What was invoiced, month by month. Credits are shown beside it and never subtracted from it.
      </p>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="text-left text-xs uppercase tracking-wide text-subtle">
            <tr>
              <th className="py-1 pr-3">Month</th>
              <th className="py-1 pr-3">Net</th>
              <th className="py-1 pr-3">VAT</th>
              <th className="py-1 pr-3">Gross</th>
              <th className="py-1 pr-3">Credited</th>
              <th className="py-1 pr-3">Invoices</th>
              <th className="py-1">State</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const currency = text(row.currency, 'EUR');
              const month = text(row.month, '—');
              const closed = row.closed === true;

              return (
                <tr
                  key={month}
                  data-turnover-month={month}
                  data-closed={String(closed)}
                  className="border-t border-line"
                >
                  <td className="py-1.5 pr-3">{month}</td>
                  <td className="py-1.5 pr-3">
                    <Money value={integer(row.net_minor_units)} currency={currency} />
                  </td>
                  <td className="py-1.5 pr-3">
                    <Money value={integer(row.vat_minor_units)} currency={currency} />
                  </td>
                  <td className="py-1.5 pr-3 font-medium">
                    <Money value={integer(row.gross_minor_units)} currency={currency} />
                  </td>
                  <td className="py-1.5 pr-3">
                    <Money value={integer(row.credited_minor_units)} currency={currency} />
                  </td>
                  <td className="py-1.5 pr-3 text-xs text-muted">
                    {integer(row.invoices_issued) ?? '—'} issued ·{' '}
                    {integer(row.invoices_paid) ?? '—'} paid
                  </td>
                  <td className="py-1.5">
                    {/* Settled, or still moving. The same figure means two
                        different things on the 2nd and the 31st. */}
                    <span
                      data-testid="month-state"
                      className={`rounded px-1.5 py-0.5 text-xs ${
                        closed
                          ? 'bg-well'
                          : 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200'
                      }`}
                    >
                      {closed ? 'settled' : 'still moving'}
                    </span>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function Money({ value, currency }: { value: number | null; currency: string }) {
  return value === null ? <>—</> : <Amount money={{ minor_units: value, currency }} />;
}

function TopOffers({ offers }: { offers: Record<string, unknown> }) {
  const rows = Array.isArray(offers.offers) ? offers.offers : [];
  const month = text(offers.month, '');

  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">Top offers</h2>
      {/* Which month, stated: over a year and over last month are different
          questions, and the contract separates them for that reason. */}
      <p data-testid="offers-month" className="text-sm text-muted">
        {month === '' ? 'Ranked over the window.' : `Ranked within ${month}.`}
      </p>

      {rows.length === 0 ? (
        <EmptyState title="No offers billed" description="Nothing was billed in that month." />
      ) : (
        <ul className="space-y-1 text-sm">
          {rows.map((row: unknown, index) => {
            const offer = typeof row === 'object' && row !== null ? (row as Record<string, unknown>) : {};

            return (
              <li
                key={text(offer.offer_id, String(index))}
                data-offer={text(offer.code, '')}
                className="flex flex-wrap items-baseline gap-2"
              >
                <span className="min-w-0 flex-1">{text(offer.name, text(offer.code, '—'))}</span>
                <span className="text-xs text-subtle">
                  {integer(offer.lines_billed) ?? '—'} lines
                </span>
                <Money value={integer(offer.net_minor_units)} currency={text(offer.currency, 'EUR')} />
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

function Renewal({ rows }: { rows: readonly Record<string, unknown>[] }) {
  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">Renewal</h2>
      <p className="text-sm text-muted">
        A month in which nothing came up for renewal has no rate. That is not 0% — nothing
        auto-renews yet, and reporting an unbuilt feature as total churn would be worse than
        reporting nothing.
      </p>

      {rows.length === 0 ? (
        <EmptyState title="No renewal data" description="No period in this window has any." />
      ) : (
        <ul className="space-y-1 text-sm">
          {rows.map((row) => {
            const month = text(row.month, '—');
            const measured = row.measured === true;
            const rate = integer(row.rate_percent);

            return (
              <li
                key={month}
                data-renewal-month={month}
                data-measured={String(measured)}
                className="flex flex-wrap items-baseline gap-2"
              >
                <span className="w-24">{month}</span>
                <span className="text-xs text-muted">
                  {integer(row.due) ?? 0} due · {integer(row.renewed) ?? 0} renewed ·{' '}
                  {integer(row.ended) ?? 0} ended
                </span>
                <span data-testid="renewal-rate" className="ml-auto">
                  {measured && rate !== null ? `${String(rate)}%` : 'nothing came up'}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
