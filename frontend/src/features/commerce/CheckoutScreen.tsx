import { Link } from '@tanstack/react-router';

import { isAwaitingPayment, useCheckoutSession } from '@/queries/checkout';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `commerce.checkout` — one lifecycle, because there is only one thing.
 *
 * **A checkout session is an order** (ADR-034): the id in the URL is the order's
 * id, `status` is derived on read, and everything a session would hold already
 * lives on the order, the invoice and the payment. So this screen shows the
 * order's progress and never a parallel "session state" that could disagree.
 *
 * **A dropped connection loses nothing**, which is U5's exit criterion. The order
 * exists the moment the session opens, its id is in the URL, and it is in
 * `/orders` — so this page is reachable again by link, by history, or by looking
 * for the order. The one thing a reload cannot recover is the `client_secret`,
 * and that is by design: it is returned once, kept in memory for exactly as long
 * as the attempt that received it, and never stored. Paying after a reload is a
 * new attempt, which the screen says rather than pretending to resume.
 *
 * The payment gate is legible straight off the document (non-negotiable #20): an
 * `invoice_id` from fulfilment onwards, a `subscription_id` only once that
 * invoice is paid. Both are shown, in that order, because "invoiced" and
 * "provisioned" are different facts and only the second means the person has
 * what they bought.
 */
export function CheckoutScreen({ sessionId }: { sessionId: string }) {
  const session = useCheckoutSession(sessionId);

  if (session.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (session.error !== null) {
    return <ErrorSurface error={session.error} onRetry={() => void session.refetch()} />;
  }

  const current = session.data;

  return (
    <div className="max-w-2xl space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Checkout</h1>
        {/* Said out loud rather than hidden behind the word "session": the id is
            the order's, so somebody comparing this screen with /orders sees the
            same number in both places. */}
        <p className="text-xs text-subtle">
          This is order <code>{current.order_id}</code>.
        </p>
      </header>

      <section className="space-y-3">
        <div className="flex flex-wrap items-center gap-3">
          <span
            data-testid="checkout-status"
            data-status={current.status}
            className={`rounded px-2 py-0.5 text-xs font-medium ${statusClass(current.status)}`}
          >
            {current.status.replace(/_/g, ' ')}
          </span>

          {isAwaitingPayment(current.status) && (
            // Polling, and saying so. Activation is payment-gated and the money
            // arrives through a webhook, so the page is waiting on something
            // that is genuinely elsewhere.
            <span data-testid="checkout-waiting" className="text-xs text-subtle">
              waiting for the payment to be confirmed…
            </span>
          )}
        </div>

        <dl className="grid grid-cols-3 gap-3 text-sm">
          <div>
            <dt className="text-xs text-subtle">Net</dt>
            <dd>
              <Amount money={current.net} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">VAT</dt>
            <dd>
              <Amount money={current.vat} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">Total</dt>
            <dd className="font-medium">
              <Amount money={current.gross} />
            </dd>
          </div>
        </dl>
      </section>

      <section className="space-y-2 border-t border-line pt-4 text-sm">
        <h2 className="text-xl font-semibold">What has happened</h2>

        {/* Two steps, never collapsed into one. An invoice exists from fulfilment;
            a subscription only once the money arrived. */}
        <ol className="space-y-1">
          <li data-testid="step-invoice">
            {current.invoice_id === null ? (
              <span className="text-subtle">
                Not invoiced yet
                <span className="ml-1 text-xs">
                  (or nothing to collect — a free offer raises no invoice)
                </span>
              </span>
            ) : (
              <>
                Invoiced — <code className="text-xs">{current.invoice_id}</code>
              </>
            )}
          </li>
          <li data-testid="step-subscription">
            {current.subscription_id === null ? (
              <span className="text-subtle">
                Not started — nothing is provisioned before the money arrives
              </span>
            ) : (
              <>
                Subscription started — <code className="text-xs">{current.subscription_id}</code>
              </>
            )}
          </li>
        </ol>
      </section>

      {current.status === 'PAYMENT_FAILED' && (
        <section
          data-testid="payment-failed"
          className="space-y-2 rounded border border-red-300 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/40"
        >
          <p className="font-medium">The last payment attempt failed.</p>
          {/* Honest about both halves: the order is still there, and the secret
              from the previous attempt is gone. A retry is a new attempt, and it
              lives with payments rather than here. */}
          <p>
            The order is intact and nothing has been charged. Retrying starts a new attempt — the
            credential from the last one is deliberately not kept, so it cannot be resumed.
          </p>
          <p className="text-xs text-muted">
            Retrying a payment is on the payment itself, which arrives with billing.
          </p>
        </section>
      )}

      <p className="text-sm">
        <Link to="/orders" className="underline decoration-dotted">
          All orders
        </Link>
        {' — this one is in there, whatever happens to this page.'}
      </p>
    </div>
  );
}

function statusClass(status: string): string {
  switch (status) {
    case 'COMPLETED':
      return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200';
    case 'PAYMENT_FAILED':
      return 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200';
    case 'CANCELLED':
      return 'bg-well text-muted';
    default:
      return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
  }
}
