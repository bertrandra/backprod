import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { addressFor } from '@/app/frame/ProductSwitcher';
import { withRoot } from '@/app/root';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useCurrentProduct } from '@/queries/catalogue';
import { isAwaitingPayment, useCancelCheckoutSession, useCheckoutSession } from '@/queries/checkout';
import { useStartPayment, type StartedPayment } from '@/queries/payments';
import { useSession } from '@/queries/session';
import { useSessionStore } from '@/state/session';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, buttonClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice, pill, type Tone } from '@/ui/tone';
import { t } from '@/i18n';

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
 *
 * **What was bought is said, and for whom** (2026-09-18). Three amounts and
 * two ids told a customer what it cost and not what it was; the order's own
 * line does, and `seat` says whether it is their own seat or the
 * organisation's subscription (§13.1). Once the money has arrived the page
 * says so in words, at the top — that is the moment a person is looking for.
 *
 * **Unpaid, the page offers the two ways out** (2026-09-18). The buyer who
 * closed the card form is back here with an order awaiting money: *Pay now*
 * starts a fresh attempt on the invoice — a new secret, born on this page and
 * used on it, since the old one is gone by design — and *Cancel this
 * purchase* gives the order up, invoice and all. Both are `billing.pay`, the
 * buyer's own permission, and both answer the operator's report that an
 * abandoned checkout left nothing to do but wait.
 */
export function CheckoutScreen({ sessionId }: { sessionId: string }) {
  const session = useCheckoutSession(sessionId);
  const { data: me } = useSession();
  const root = useSessionStore((state) => state.root);
  const cancel = useCancelCheckoutSession();
  // A fresh attempt, held for exactly as long as the render that received
  // its secret (ADR-034, ADR-048).
  const [attempt, setAttempt] = useState<StartedPayment | null>(null);
  const [confirming, setConfirming] = useState(false);

  if (session.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (session.error !== null) {
    return <ErrorSurface error={session.error} onRetry={() => void session.refetch()} />;
  }

  const current = session.data;
  // A stub from before the field existed answers nothing here; nothing
  // is derived from its absence but a plainer sentence.
  const description = current.description ?? null;
  const forSelf = current.seat === true;
  // Unpaid and payable: awaiting, or the last attempt failed. Either way a
  // new attempt is the answer and giving up is the alternative.
  const unpaid =
    (isAwaitingPayment(current.status) || current.status === 'PAYMENT_FAILED') && current.invoice_id !== null;
  const mayAct = unpaid && can(me, 'billing.pay');

  return (
    <div className="max-w-2xl space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">{t("Checkout")}</h1>
        {description !== null && (
          <p data-testid="checkout-description" data-seat={forSelf} className="text-sm">
            <span className="font-medium">{description}</span>
            {' — '}
            {forSelf ? t("your own seat") : t("for the organisation")}
          </p>
        )}
        {/* Said out loud rather than hidden behind the word "session": the id is
            the order's, so somebody comparing this screen with /orders sees the
            same number in both places. */}
        <p className="text-xs text-subtle">
          {t("This is order")}{' '}<code>{current.order_id}</code>.
        </p>
      </header>

      {current.status === 'COMPLETED' && (
        <section data-testid="checkout-completed" className={`${notice('success')} space-y-3`}>
          <div className="space-y-1">
            <p className="font-medium">{t("Paid — thank you.")}</p>
            <p>
              {forSelf
                ? t("Your seat has started; what it entitles you to is yours now.")
                : t("The subscription has started; everyone in the organisation is entitled to it now.")}
            </p>
          </div>

          {/* **The way in to the thing they just bought** (2026-09-24). The
              page said the money had arrived and then offered one link, to
              the order list — so somebody who had just paid for a seat was
              left on a receipt, with the product two guesses away. The
              operator watched it happen.

              Where the product runs beside the platform it is the product's
              own address, because that is where the work is; otherwise the
              project list, which is where it is here. Either way it is a
              real link and not a button that navigates: it can be opened in
              a new tab and read in the status bar first. */}
          <StartWorking />
        </section>
      )}

      <section className="space-y-3">
        <div className="flex flex-wrap items-center gap-3">
          <span
            data-testid="checkout-status"
            data-status={current.status}
            className={pill(statusTone(current.status))}
          >
            {current.status.replace(/_/g, ' ')}
          </span>

          {isAwaitingPayment(current.status) && (
            // Polling, and saying so. Activation is payment-gated and the money
            // arrives through a webhook, so the page is waiting on something
            // that is genuinely elsewhere.
            <span data-testid="checkout-waiting" className="text-xs text-subtle">
              {t("waiting for the payment to be confirmed…")}</span>
          )}
        </div>

        <dl className="grid grid-cols-3 gap-3 text-sm">
          <div>
            <dt className="text-xs text-subtle">{t("Net")}</dt>
            <dd>
              <Amount money={current.net} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">{t("VAT")}</dt>
            <dd>
              <Amount money={current.vat} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">{t("Total")}</dt>
            <dd className="font-medium">
              <Amount money={current.gross} />
            </dd>
          </div>
        </dl>
      </section>

      <section className="space-y-2 border-t border-line pt-4 text-sm">
        <h2 className="text-xl font-semibold">{t("What has happened")}</h2>

        {/* Two steps, never collapsed into one. An invoice exists from fulfilment;
            a subscription only once the money arrived. */}
        <ol className="space-y-1">
          <li data-testid="step-invoice">
            {current.invoice_id === null ? (
              <span className="text-subtle">
                {t("Not invoiced yet")}<span className="ml-1 text-xs">
                  {t("(or nothing to collect — a free offer raises no invoice)")}</span>
              </span>
            ) : (
              <>
                {t("Invoiced —")}{' '}<code className="text-xs">{current.invoice_id}</code>
              </>
            )}
          </li>
          <li data-testid="step-subscription">
            {current.subscription_id === null ? (
              <span className="text-subtle">
                {t("Not started — nothing is provisioned before the money arrives")}</span>
            ) : (
              <>
                {forSelf ? t("Seat started") : t("Subscription started")} —{' '}
                <code className="text-xs">{current.subscription_id}</code>
              </>
            )}
          </li>
        </ol>
      </section>

      {current.status === 'HELD' && (
        <section data-testid="payment-held" className={`${notice('warning')} space-y-2`}>
          <p className="font-medium">{t("Paid, and not started.")}</p>
          {/* The one race the catalogue's refusal cannot reach: this order was
              opened before another one was paid, and by the time this payment
              arrived the subscription it was for had already begun. Nothing was
              started twice; the money is recorded and is the operator's to
              return. */}
          <p>
            {forSelf
              ? t("The payment was collected, but you already held a live seat on this product by the time it arrived, so nothing was started against it. Nothing has been provisioned twice — the payment will be refunded.")
              : t("The payment was collected, but this organisation already had a live subscription to this product by the time it arrived, so nothing was started against it. Nothing has been provisioned twice — the payment will be refunded.")}
          </p>
        </section>
      )}

      {current.status === 'PAYMENT_FAILED' && (
        <section
          data-testid="payment-failed"
          className={`${notice('danger')} space-y-2`}
        >
          <p className="font-medium">{t("The last payment attempt failed.")}</p>
          {/* Honest about both halves: the order is still there, and the secret
              from the previous attempt is gone. A retry is a new attempt. */}
          <p>
            {t("The order is intact and nothing has been charged. Paying again starts a new attempt — the credential from the last one is deliberately not kept, so it cannot be resumed.")}</p>
        </section>
      )}

      {current.status === 'CANCELLED' && (
        <section data-testid="checkout-cancelled" className={`${notice('neutral')} space-y-1`}>
          <p className="font-medium">{t("This purchase was cancelled.")}</p>
          <p>{t("Nothing was charged and nothing was started. The catalogue has the same offer if you change your mind.")}</p>
        </section>
      )}

      {mayAct && current.invoice_id !== null && (
        <section data-testid="checkout-actions" className="space-y-3 border-t border-line pt-4">
          {attempt !== null ? (
            <PayNow
              attempt={attempt}
              returnUrl={new URL(withRoot(root, `/checkout/${current.id}`), window.location.origin).toString()}
              onSettled={() => {
                setAttempt(null);
                void session.refetch();
              }}
            />
          ) : (
            <>
              <h2 className="text-xl font-semibold">{t("Not paid yet")}</h2>
              <p className="text-sm text-muted">
                {t("Pay it now — a fresh attempt, with a new card form — or give the purchase up. Giving it up cancels its invoice too; nothing has been charged either way.")}</p>
              {cancel.error !== null && <ErrorSurface error={cancel.error} />}
              <div className="flex flex-wrap gap-2">
                <StartAttempt invoiceId={current.invoice_id} onStarted={setAttempt} />
                {confirming ? (
                  <>
                    <Button
                      type="button"
                      variant="danger"
                      pending={cancel.isPending}
                      data-testid="cancel-checkout"
                      onClick={() => cancel.mutate(current.id, { onSettled: () => setConfirming(false) })}
                    >
                      {t("Cancel this purchase")}</Button>
                    <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                      {t("Keep it")}</Button>
                  </>
                ) : (
                  <Button type="button" variant="secondary" onClick={() => setConfirming(true)}>
                    {t("Cancel this purchase…")}</Button>
                )}
              </div>
            </>
          )}
        </section>
      )}

      <p className="text-sm">
        <Link to="/orders" className="underline decoration-dotted">
          {t("All orders")}</Link>
        {t(" — this one is in there, whatever happens to this page.")}
      </p>
    </div>
  );
}

/**
 * The button that asks for a new attempt. Its own component so the mutation
 * is keyed to the invoice it collects, as `useStartPayment` requires.
 */
function StartAttempt({ invoiceId, onStarted }: { invoiceId: string; onStarted: (attempt: StartedPayment) => void }) {
  const start = useStartPayment(invoiceId);

  return (
    <>
      {start.error !== null && <ErrorSurface error={start.error} />}
      <Button
        type="button"
        pending={start.isPending}
        data-testid="pay-now"
        onClick={() => start.mutate(undefined, { onSuccess: onStarted })}
      >
        {t("Pay now")}</Button>
    </>
  );
}

/**
 * The card form for a fresh attempt, where its secret was born (ADR-048).
 * Whatever the form says, the page's own read says what the server knows.
 */
function PayNow({
  attempt,
  returnUrl,
  onSettled,
}: {
  attempt: StartedPayment;
  returnUrl: string;
  onSettled: () => void;
}) {
  return (
    <div className="max-w-lg space-y-3" data-testid="checkout-pay">
      <h2 className="text-xl font-semibold">{t("Pay")}</h2>
      <PaymentElementPanel
        provider={attempt.payment_provider}
        clientSecret={attempt.client_secret}
        amount={attempt.amount}
        returnUrl={returnUrl}
        onSettled={onSettled}
      />
    </div>
  );
}

/**
 * Where somebody goes once they have paid.
 *
 * The product's own address when it runs beside the platform (ADR-051 §3) —
 * `?product=` and nothing else, no token, exactly as every other door to it
 * does — and the project list otherwise.
 *
 * The product is the one the session is already in, so there is nothing to
 * choose and nothing to pass: what somebody just bought, they bought here.
 */
function StartWorking() {
  const product = useCurrentProduct();
  const appUrl = product?.app_url ?? null;

  if (appUrl !== null && appUrl !== '') {
    return (
      <a
        href={addressFor(appUrl, product?.code ?? '')}
        rel="noreferrer"
        data-testid="start-working"
        className={buttonClass()}
      >
        {t("Open {product}", { product: product?.name ?? '' })}
      </a>
    );
  }

  return (
    <Link to="/projects" data-testid="start-working" className={buttonClass()}>
      {t("Go to your projects")}</Link>
  );
}

function statusTone(status: string): Tone {
  switch (status) {
    case 'COMPLETED':
      return 'success';
    case 'PAYMENT_FAILED':
      return 'danger';
    case 'HELD':
      return 'warning';
    case 'CANCELLED':
      return 'neutral';
    default:
      return 'warning';
  }
}
