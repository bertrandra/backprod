import type { Schemas } from '@/api/client';
import { t } from '@/i18n';

export type QuotaUsage = Schemas['QuotaUsage'];

/**
 * How much of one quota is used, rendered once.
 *
 * Asked for by the operator, who had bought a plan and could not see what it
 * allowed them or how much of it was left. The figures were already in the
 * contract — `showTenantUsage` has answered them since M4 — but `usage` was
 * typed `additionalProperties: true`, so the generated client handed back
 * `unknown` and no screen could read a field of it. The one place that tried
 * printed every key of every row into a table cell: `feature max_projects
 * name Projects unit projects limit 3 unlimited false metered true used 1
 * remaining 2`, which is a debug dump wearing a heading.
 *
 * It lives in `ui/` because three screens show the same quota — the
 * organisation's register, the subscription, and the workspace that refuses on
 * it — and a quota rendered three times is a figure a customer paid for,
 * explained three ways. The one that matters is the refusal: the sentence
 * beside the bar has to agree with what the server will do at the next click.
 *
 * **Four answers, and they are four different answers.** This is the whole
 * reason the row carries `unlimited` and `metered` beside the numbers:
 *
 *   - **unlimited** — no denominator, so no bar. A bar at 0% would say "none
 *     of your allowance used" about an allowance that has no end.
 *   - **not counted** — a limit the offer records and nothing enforces
 *     (`max_storage` until storage exists). Shown as the limit plus the words:
 *     printing "0 used" would tell a customer their limit is being watched
 *     when nothing is watching it, which is the lie `metered` exists to
 *     prevent.
 *   - **counted, with a limit** — the bar, the figure, and what is left.
 *   - **counted, no limit recorded** — the reading alone. `limit: null` with
 *     `unlimited: false` should not occur, and inventing a denominator for it
 *     would draw a bar against a number nobody sold.
 *
 * Every number here is the server's. `remaining` is not `limit - used`
 * computed on the page: the server already answers it, and a second
 * subtraction is a second answer to "how many may I still create" — the first
 * one being the one that refuses. (§4: a component transports and renders.)
 *
 * The bar is **clamped and the words are not**. A quota can be exceeded — an
 * offer moved down leaves eleven projects against a limit of three — and the
 * fill stops at the end of the track while the figure still reads eleven of
 * three. A bar drawn past its track would be a drawing bug; a figure rounded
 * down to the limit would be a lie about the customer's own data.
 *
 * Being full is `warning`, never `danger`. Reaching a limit you bought is not
 * a fault, and the red is kept for things that went wrong.
 */
export function Meter({ quota }: { quota: QuotaUsage }) {
  const used = quota.used;
  const limit = quota.limit;

  // "Counted" needs both: `metered` says something is watching the feature,
  // and a reading says it answered. A product that metered itself and has
  // reported nothing yet has the first and not the second.
  const counted = quota.metered && used !== null;
  const bounded = !quota.unlimited && limit !== null;

  return (
    <div data-quota={quota.feature} className="space-y-1">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <span className="min-w-0 font-medium">{quota.name}</span>
        <span data-testid="quota-figure" className="text-sm tabular-nums text-muted">
          {counted && bounded
            ? amount(used, limit, quota.unit)
            : counted
              ? t("{count} used", { count: count(used) })
              : bounded
                ? withUnit(limit, quota.unit)
                : t("unlimited")}
        </span>
      </div>

      {counted && bounded && <Track name={quota.name} used={used} limit={limit} />}

      <p className="text-xs text-subtle">
        {!quota.metered
          ? // Said plainly rather than left to be inferred from a missing bar.
            // "Did I use any of this?" has an answer, and it is "nobody knows".
            t("Not counted yet — this limit is recorded and nothing is enforcing it.")
          : counted && bounded
            ? remaining(quota.remaining, quota.unit)
            : quota.unlimited
              ? t("No limit on this.")
              : t("Nothing has reported a reading for this yet.")}
      </p>
    </div>
  );
}

/**
 * The bar.
 *
 * `role="progressbar"` with the three values, because a bar a screen reader
 * cannot read is decoration — and this one carries the only graphic answer on
 * the row. `aria-valuetext` is left to the browser: the figure beside it is
 * already in the accessible tree, and a second rendering of the same number
 * would be read out twice.
 */
function Track({ name, used, limit }: { name: string; used: number; limit: number }) {
  // A quota of zero has no proportion to draw, and anything used against it is
  // past the end. Guarded here rather than at the caller, because dividing by
  // it is this function's business.
  const share = limit === 0 ? (used > 0 ? 100 : 0) : Math.min(100, Math.round((used / limit) * 100));
  const full = used >= limit;

  return (
    <div
      role="progressbar"
      aria-label={name}
      aria-valuenow={used}
      aria-valuemin={0}
      aria-valuemax={limit}
      className="h-1.5 overflow-hidden rounded-full bg-line"
    >
      <div
        data-testid="quota-fill"
        data-full={full ? 'yes' : undefined}
        className={full ? 'h-full bg-warning' : 'h-full bg-accent'}
        // The one place an inline style is right: the width *is* the datum, and
        // a Tailwind class cannot carry an arbitrary percentage that changes on
        // every render.
        style={{ width: `${String(share)}%` }}
      />
    </div>
  );
}

/** Counts, in the reader's locale: `1 200`, not `1200`. */
function count(value: number): string {
  return new Intl.NumberFormat(undefined).format(value);
}

function withUnit(value: number, unit: string | null): string {
  return unit === null ? count(value) : t("{count} {unit}", { count: count(value), unit });
}

function amount(used: number, limit: number, unit: string | null): string {
  return unit === null
    ? t("{used} of {limit}", { used: count(used), limit: count(limit) })
    : t("{used} of {limit} {unit}", { used: count(used), limit: count(limit), unit });
}

/**
 * What is left, from the server's own figure.
 *
 * Null here would mean the server declined to give one, which on a counted and
 * bounded quota it does not — so the branch exists for the contract's sake and
 * says nothing rather than guessing a difference.
 */
function remaining(left: number | null, unit: string | null): string {
  if (left === null) {
    return '';
  }

  // Zero left is its own sentence. "0 left" reads as a measurement; "none
  // left" reads as the answer to the question somebody is about to ask.
  return left === 0 ? t("None left.") : t("{count} left.", { count: withUnit(left, unit) });
}

/**
 * The quotas of a set of rows, in the order the server gave them.
 *
 * Not sorted here: the server lists them in the order the offer grants them,
 * and re-sorting by name would put a product's own quotas in a different order
 * on every screen that shows them.
 */
export function Meters({ quotas }: { quotas: readonly QuotaUsage[] }) {
  return (
    <ul data-testid="quota-meters" className="space-y-4">
      {quotas.map((quota) => (
        <li key={quota.feature}>
          <Meter quota={quota} />
        </li>
      ))}
    </ul>
  );
}
