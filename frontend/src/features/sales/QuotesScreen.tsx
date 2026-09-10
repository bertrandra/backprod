import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { useViewState } from '@/app/frame/viewState';
import { useAcceptQuote, useQuotes, useRejectQuote, type Quote } from '@/queries/sales';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount, formatVatRate } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `sales.quotes` — and the first action in this application that cannot be undone.
 *
 * **Rejecting is irreversible**, so it is confirmed. Not with a modal that a
 * double-click dismisses, and not with a "are you sure?" that means nothing: the
 * row itself asks, in place, and the confirming button says what it does.
 * Accepting is equally final but produces something — an order — so the screen
 * follows the person to it rather than leaving them to look.
 *
 * **`open` is the server's answer, never recomputed here.** It is derived from
 * the clock, which is what lets an expired quote read as closed with nothing
 * having swept it. A screen comparing `valid_until` to `Date.now()` would reach a
 * *different* answer a second later, and then two answers would exist for one
 * quote — so `valid_until` is shown as information and `open` is what decides
 * whether the buttons appear.
 */
export function QuotesScreen() {
  const navigate = useNavigate();
  const { selected } = useViewState();
  const quotes = useQuotes();
  const accept = useAcceptQuote();
  const reject = useRejectQuote();

  const [confirming, setConfirming] = useState<string | null>(null);

  if (quotes.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (quotes.error !== null) {
    return <ErrorSurface error={quotes.error} onRetry={() => void quotes.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-lg font-semibold">Quotes</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {quotes.data.total} in this product
        </span>
      </div>

      {accept.error !== null && <ErrorSurface error={accept.error} />}
      {reject.error !== null && <ErrorSurface error={reject.error} />}

      {quotes.data.quotes.length === 0 ? (
        <EmptyState
          title="No quotes"
          description="A quote is raised from an offer in the catalogue."
        />
      ) : (
        <ul className="space-y-2">
          {quotes.data.quotes.map((quote) => (
            <li
              key={quote.id}
              data-quote={quote.id}
              data-status={quote.status}
              data-open={quote.open ? 'true' : 'false'}
              className={
                selected === quote.id
                  ? 'rounded border border-neutral-400 bg-neutral-50 p-3 text-sm dark:border-neutral-600 dark:bg-neutral-900'
                  : 'rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800'
              }
            >
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded bg-neutral-200 px-1.5 py-0.5 text-xs font-medium dark:bg-neutral-800">
                  {quote.status}
                </span>
                {/* Both facts, because they answer different questions: what the
                    document says, and whether it can still be acted on now. */}
                {!quote.open && quote.status === 'SENT' && (
                  <span data-testid="expired" className="text-xs text-neutral-500">
                    no longer open — the validity date has passed
                  </span>
                )}
                <span className="ml-auto text-xs text-neutral-500">
                  valid until {new Date(quote.valid_until).toLocaleDateString()}
                </span>
              </div>

              <div className="mt-2 flex flex-wrap items-baseline gap-3">
                <Amount money={quote.gross} className="font-medium" />
                <span className="text-xs text-neutral-500">
                  net <Amount money={quote.net} /> · VAT <Amount money={quote.vat} />
                </span>
              </div>

              <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                {/* The pinned version, which is why a catalogue change cannot
                    reprice this quote. */}
                priced by offer version <code>{quote.offer_version_id}</code>
              </p>

              {quote.open && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {confirming === quote.id ? (
                    <>
                      <span className="w-full text-xs text-neutral-700 dark:text-neutral-300">
                        Rejecting a quote cannot be undone. A new quote would have to be raised.
                      </span>
                      <Button
                        type="button"
                        variant="danger"
                        pending={reject.isPending}
                        onClick={() =>
                          reject.mutate(quote.id, { onSettled: () => setConfirming(null) })
                        }
                      >
                        Reject permanently
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setConfirming(null)}
                      >
                        Keep it open
                      </Button>
                    </>
                  ) : (
                    <>
                      <Button
                        type="button"
                        pending={accept.isPending}
                        onClick={() =>
                          accept.mutate(quote.id, {
                            onSuccess: (order) => {
                              // Acceptance produced an order. Going there is the
                              // point of having accepted.
                              void navigate({ to: '/orders', search: { selected: order.id } });
                            },
                          })
                        }
                      >
                        Accept
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setConfirming(quote.id)}
                      >
                        Reject
                      </Button>
                    </>
                  )}
                </div>
              )}

              <QuoteLines quote={quote} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function QuoteLines({ quote }: { quote: Quote }) {
  if (quote.lines.length === 0) {
    return null;
  }

  return (
    <ul className="mt-3 space-y-1 border-t border-neutral-200 pt-2 text-xs dark:border-neutral-800">
      {quote.lines.map((line) => (
        // Keyed by `position`, which the document defines and the API guarantees
        // — an array index would renumber the lines if the order ever changed.
        <li key={line.position} className="flex flex-wrap gap-2">
          <span className="min-w-0 flex-1 truncate">{line.description}</span>
          <span className="text-neutral-500">×{line.quantity}</span>
          <span className="text-neutral-500">VAT {formatVatRate(line.vat_rate_basis_points)}</span>
          {/* Rendered, never summed: every total is the server's and appears above. */}
          <Amount money={line.net} />
        </li>
      ))}
    </ul>
  );
}
