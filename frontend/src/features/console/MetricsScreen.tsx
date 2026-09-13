import { useState } from 'react';

import { useMetrics } from '@/queries/admin';
import { useProducts } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { PageHeader, Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { Sparkline, StatRow, StatTile } from '@/ui/Stat';
import { Table, TBody, Td, Th, THead, TR } from '@/ui/Table';
import { pill } from '@/ui/tone';

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
 *
 * **The figures come before the tables, which is new.** This screen used to open
 * on three tables: to learn what the product earned last month you read down
 * twelve rows and worked out which was the last settled one. The tables are
 * still here, unchanged and complete, because seven columns of audit detail is
 * exactly what a table is for — but the number somebody came to see is now the
 * first thing on the page, and the sparkline beside it says which way it has
 * been going without asking anyone to read a column.
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
      <PageHeader
        title="Metrics"
        description="Turnover, the offers that earned it, and renewal — for one product. There is no combined figure across products: different currencies and different catalogues do not add up."
      />

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
          <Headline turnover={metrics.data.turnover} renewal={metrics.data.renewal} />
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

/**
 * What the product earned, and which way it is going.
 *
 * **Drawn from the same rows the table below shows**, not from a second
 * endpoint — so a figure here can never disagree with the row it came from. The
 * last row is the current month and the reason `closed` is carried up: a gross
 * figure on the 2nd and the same figure on the 31st are different claims, and a
 * tile that did not say which would be the most quotable number on the page.
 *
 * Renewal's headline is the last **measured** month rather than the last month.
 * A month where nothing came up has no rate, and carrying that forward as 0%
 * would report an unbuilt feature as total churn.
 */
function Headline({
  turnover,
  renewal,
}: {
  turnover: readonly Record<string, unknown>[];
  renewal: readonly Record<string, unknown>[];
}) {
  const latest = turnover.at(-1);

  if (latest === undefined) {
    return null;
  }

  const currency = text(latest.currency, 'EUR');
  const closed = latest.closed === true;
  const month = text(latest.month, '—');

  const issued = integer(latest.invoices_issued);
  const paid = integer(latest.invoices_paid);

  // The last month anybody actually renewed in. `measured` is the contract's
  // word for "something was due"; without it there is no rate to report.
  const measured = renewal.filter((row) => row.measured === true);
  const lastMeasured = measured.at(-1);

  const state = (
    <span data-testid="headline-state" className={pill(closed ? 'neutral' : 'warning')}>
      {closed ? 'settled' : 'still moving'}
    </span>
  );

  return (
    <StatRow>
      <StatTile
        label="Gross invoiced"
        state={state}
        value={<Money value={integer(latest.gross_minor_units)} currency={currency} />}
        trend={
          <Sparkline
            label={`Gross invoiced over the last ${String(turnover.length)} months`}
            values={turnover.map((row) => integer(row.gross_minor_units))}
          />
        }
        detail={month}
      />

      <StatTile
        label="Credited"
        value={<Money value={integer(latest.credited_minor_units)} currency={currency} />}
        trend={
          <Sparkline
            label={`Credited over the last ${String(turnover.length)} months`}
            values={turnover.map((row) => integer(row.credited_minor_units))}
          />
        }
        // Said on the tile and not only in the section below: this is the one
        // figure somebody is most likely to net off the one beside it. Worded
        // differently from the section on purpose — the same sentence twice on
        // one screen reads as a template, not as a warning.
        detail="Never netted off the figure beside it"
      />

      <StatTile
        label="Invoices paid"
        value={
          issued === null || paid === null ? '—' : `${String(paid)} / ${String(issued)}`
        }
        detail={issued === null ? 'Not reported' : `Paid of issued, in ${month}`}
      />

      <StatTile
        label="Renewal"
        value={
          lastMeasured === undefined
            ? 'Nothing due'
            : `${String(integer(lastMeasured.rate_percent) ?? 0)}%`
        }
        trend={
          <Sparkline
            label="Renewal rate over the months that had anything due"
            values={renewal.map((row) =>
              row.measured === true ? integer(row.rate_percent) : null,
            )}
          />
        }
        detail={
          lastMeasured === undefined
            ? 'No month in this window had a subscription come up'
            : text(lastMeasured.month, '')
        }
      />
    </StatRow>
  );
}

function Turnover({ rows }: { rows: readonly Record<string, unknown>[] }) {
  if (rows.length === 0) {
    return (
      <EmptyState title="No turnover" description="Nothing has been invoiced in this window." />
    );
  }

  return (
    <Section
      title="Turnover"
      description="What was invoiced, month by month. Credits are shown beside it and never subtracted from it."
    >
      <Table caption="Turnover by month: net, VAT, gross, credited, invoice counts and whether the month has settled">
        <THead>
          <Th>Month</Th>
          <Th numeric>Net</Th>
          <Th numeric>VAT</Th>
          <Th numeric>Gross</Th>
          <Th numeric>Credited</Th>
          <Th numeric>Issued</Th>
          <Th numeric>Paid</Th>
          <Th>State</Th>
        </THead>

        <TBody>
          {rows.map((row) => {
            const currency = text(row.currency, 'EUR');
            const month = text(row.month, '—');
            const closed = row.closed === true;

            return (
              <TR key={month} data-turnover-month={month} data-closed={String(closed)}>
                <Td className="font-medium">{month}</Td>
                <Td numeric>
                  <Money value={integer(row.net_minor_units)} currency={currency} />
                </Td>
                <Td numeric>
                  <Money value={integer(row.vat_minor_units)} currency={currency} />
                </Td>
                {/* The column people came for, so it carries the weight. */}
                <Td numeric className="font-medium">
                  <Money value={integer(row.gross_minor_units)} currency={currency} />
                </Td>
                <Td numeric>
                  <Money value={integer(row.credited_minor_units)} currency={currency} />
                </Td>
                <Td numeric className="text-muted">{integer(row.invoices_issued) ?? '—'}</Td>
                <Td numeric className="text-muted">{integer(row.invoices_paid) ?? '—'}</Td>
                <Td>
                  {/* Settled, or still moving. The same figure means two
                      different things on the 2nd and the 31st. */}
                  <span data-testid="month-state" className={pill(closed ? 'neutral' : 'warning')}>
                    {closed ? 'settled' : 'still moving'}
                  </span>
                </Td>
              </TR>
            );
          })}
        </TBody>
      </Table>
    </Section>
  );
}

function Money({ value, currency }: { value: number | null; currency: string }) {
  return value === null ? <>—</> : <Amount money={{ minor_units: value, currency }} />;
}

/**
 * Which offers earned it, ranked.
 *
 * A bar per offer and not a pie: the question is "how do these compare", which
 * is a length comparison, and a pie asks people to compare angles instead. Every
 * bar is the same hue — the magnitude is in the *length*, and giving each offer
 * its own colour would imply the colours meant something.
 */
function TopOffers({ offers }: { offers: Record<string, unknown> }) {
  const rows = Array.isArray(offers.offers) ? offers.offers : [];
  const month = text(offers.month, '');

  const amounts = rows.map((row: unknown) => {
    const offer = typeof row === 'object' && row !== null ? (row as Record<string, unknown>) : {};

    return integer(offer.net_minor_units) ?? 0;
  });

  // The largest bar is full width, so the shape reads at any scale. A window
  // where everything is zero would divide by zero; there it draws nothing.
  const largest = Math.max(0, ...amounts);

  return (
    <Section
      title="Top offers"
      // Which month, stated: over a year and over last month are different
      // questions, and the contract separates them for that reason.
      description={
        <span data-testid="offers-month">
          {month === '' ? 'Ranked over the window.' : `Ranked within ${month}.`}
        </span>
      }
    >
      {rows.length === 0 ? (
        <EmptyState title="No offers billed" description="Nothing was billed in that month." />
      ) : (
        <ul className="space-y-2.5">
          {rows.map((row: unknown, index) => {
            const offer =
              typeof row === 'object' && row !== null ? (row as Record<string, unknown>) : {};
            const net = integer(offer.net_minor_units) ?? 0;
            const share = largest === 0 ? 0 : (net / largest) * 100;

            return (
              <li
                key={text(offer.offer_id, String(index))}
                data-offer={text(offer.code, '')}
                className="space-y-1"
              >
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-sm">
                  <span className="min-w-0 flex-1 truncate">
                    {text(offer.name, text(offer.code, '—'))}
                  </span>
                  <span className="text-xs text-subtle">
                    {integer(offer.lines_billed) ?? '—'} lines
                  </span>
                  <span className="font-medium tabular-nums">
                    <Money
                      value={integer(offer.net_minor_units)}
                      currency={text(offer.currency, 'EUR')}
                    />
                  </span>
                </div>

                {/* The figure beside it is the accessible value; this is its
                    shape, so the track is decoration and says nothing. */}
                <div aria-hidden="true" className="h-1.5 overflow-hidden rounded-full bg-well">
                  <div
                    className="h-full rounded-full bg-accent"
                    style={{ width: `${String(Math.max(share, net > 0 ? 2 : 0))}%` }}
                  />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Section>
  );
}

function Renewal({ rows }: { rows: readonly Record<string, unknown>[] }) {
  return (
    <Section
      title="Renewal"
      description="A month in which nothing came up for renewal has no rate. That is not 0% — nothing auto-renews yet, and reporting an unbuilt feature as total churn would be worse than reporting nothing."
    >
      {rows.length === 0 ? (
        <EmptyState title="No renewal data" description="No period in this window has any." />
      ) : (
        <Table caption="Renewal by month: how many came up, how many renewed, how many ended, and the resulting rate">
          <THead>
            <Th>Month</Th>
            <Th numeric>Due</Th>
            <Th numeric>Renewed</Th>
            <Th numeric>Ended</Th>
            <Th numeric>Rate</Th>
          </THead>

          <TBody>
            {rows.map((row) => {
              const month = text(row.month, '—');
              const measured = row.measured === true;
              const rate = integer(row.rate_percent);

              return (
                <TR
                  key={month}
                  data-renewal-month={month}
                  data-measured={String(measured)}
                  // A month with nothing due is context, not a result.
                  muted={!measured}
                >
                  <Td className="font-medium">{month}</Td>
                  <Td numeric>{integer(row.due) ?? 0}</Td>
                  <Td numeric>{integer(row.renewed) ?? 0}</Td>
                  <Td numeric>{integer(row.ended) ?? 0}</Td>
                  <Td numeric={measured} className={measured ? 'font-medium' : 'text-right text-xs'}>
                    <span data-testid="renewal-rate">
                      {measured && rate !== null ? `${String(rate)}%` : 'nothing came up'}
                    </span>
                  </Td>
                </TR>
              );
            })}
          </TBody>
        </Table>
      )}
    </Section>
  );
}
