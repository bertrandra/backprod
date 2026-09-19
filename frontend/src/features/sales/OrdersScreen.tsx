import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useCancelOrder, useFulfilOrder, useOrders, type Order } from '@/queries/sales';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { LineOfferSummary } from '@/ui/LineOffer';
import { Amount } from '@/ui/Money';
import { When } from '@/ui/When';
import { SkeletonRows } from '@/ui/Skeleton';

import { useViewState } from '@/app/frame/viewState';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';
import { t } from '@/i18n';

/**
 * `sales.orders` — and the payment gate, read straight off the document.
 *
 * Non-negotiable #20 in two fields: an `invoice_id` from fulfilment onwards, a
 * `subscription_id` only once that invoice is paid. Nothing is provisioned before
 * the money arrives, so the screen shows the two as separate steps and never
 * collapses them into a tick. "Invoiced" and "the customer has what they bought"
 * are different facts, and only one of them is a delivery.
 *
 * Fulfilling and cancelling both change money, so neither is optimistic and both
 * come back from the server before anything on screen moves. Cancelling is
 * confirmed in place — an order cancelled by mistake is not undone by a refresh.
 *
 * An order that came from a quote says so and links back: the quote is the reason
 * the price is what it is, and `offer_version_id` is what pinned it.
 */
export function OrdersScreen() {
  const { selected } = useViewState();
  const { data: session } = useSession();
  const orders = useOrders();
  const fulfil = useFulfilOrder();
  const cancel = useCancelOrder();

  const [confirming, setConfirming] = useState<string | null>(null);

  const mayManage = can(session, 'sales.manage');

  if (orders.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (orders.error !== null) {
    return <ErrorSurface error={orders.error} onRetry={() => void orders.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader
        title={t("Orders")}
        meta={<>{orders.data.total} {t("in this product")}</>}
      />

      {fulfil.error !== null && <ErrorSurface error={fulfil.error} />}
      {cancel.error !== null && <ErrorSurface error={cancel.error} />}

      {orders.data.orders.length === 0 ? (
        <EmptyState
          title={t("No orders")}
          description={t("An order is placed from the catalogue, or created by accepting a quote.")}
        />
      ) : (
        <ul className="space-y-2">
          {orders.data.orders.map((order) => (
            <li
              key={order.id}
              data-order={order.id}
              data-status={order.status}
              className={
                selected === order.id
                  ? 'rounded border border-accent bg-accent-wash p-3 text-sm'
                  : 'rounded-card border border-line bg-surface p-4 shadow-raise text-sm'
              }
            >
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={pill(statusTone(order.status))}
                >
                  {order.status.replace(/_/g, ' ')}
                </span>
                {order.quote_id !== null && (
                  <span className="text-xs text-subtle">{t("from a quote")}</span>
                )}
                <span className="ml-auto text-xs text-subtle">
                  {t("placed")}{' '}<When at={order.created_at} />
                  {order.completed_at !== null && (
                    <>
                      {' · '}{t("completed")}{' '}<When at={order.completed_at} />
                    </>
                  )}
                </span>
              </div>

              {/* What was bought and for whom, in words (2026-09-19): the
                  ids that stood here were nobody's to read. */}
              <div className="mt-2 flex flex-wrap items-center gap-2" data-testid="order-what">
                {order.lines[0] !== undefined ? (
                  <LineOfferSummary line={order.lines[0]} />
                ) : (
                  <span className="text-subtle">{t("Nothing on this order")}</span>
                )}
                <span className="text-xs text-subtle" data-testid="order-for">
                  {order.seat === true ? t("for yourself — a seat") : t("for the organisation")}
                </span>
              </div>

              <div className="mt-2 flex flex-wrap items-baseline gap-3">
                <Amount money={order.gross} className="font-medium" />
                <span className="text-xs text-subtle">
                  {t("net")}{' '}<Amount money={order.net} /> {t("· VAT")}{' '}<Amount money={order.vat} />
                </span>
              </div>

              <PaymentGate order={order} />

              {mayManage && order.status !== 'CANCELLED' && order.status !== 'COMPLETED' && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {confirming === order.id ? (
                    <>
                      <span className="w-full text-xs text-muted">
                        {t("Cancelling an order cannot be undone.")}</span>
                      <Button
                        type="button"
                        variant="danger"
                        pending={cancel.isPending}
                        onClick={() =>
                          cancel.mutate(order.id, { onSettled: () => setConfirming(null) })
                        }
                      >
                        {t("Cancel permanently")}</Button>
                      <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                        {t("Keep it")}</Button>
                    </>
                  ) : (
                    <>
                      {order.invoice_id === null && (
                        <Button
                          type="button"
                          pending={fulfil.isPending}
                          onClick={() => fulfil.mutate(order.id)}
                        >
                          {t("Fulfil")}</Button>
                      )}
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setConfirming(order.id)}
                      >
                        {t("Cancel order")}</Button>
                    </>
                  )}
                </div>
              )}

              {order.status === 'AWAITING_PAYMENT' && (
                <p className="mt-2 text-xs">
                  <Link
                    to="/checkout/$sessionId"
                    params={{ sessionId: order.id }}
                    className="underline decoration-dotted"
                  >
                    {t("Open the checkout for this order")}</Link>
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * The two steps, never one.
 *
 * `invoice_id` says a document exists and money is owed. `subscription_id` says
 * the money arrived and the thing was provisioned. A single "paid ✓" would erase
 * the difference, and the difference is the whole of #20.
 */
function PaymentGate({ order }: { order: Order }) {
  return (
    <ol className="mt-2 space-y-1 text-xs" data-testid="payment-gate">
      <li data-testid="gate-invoice">
        {order.invoice_id === null ? (
          <span className="text-subtle">{t("Not invoiced")}</span>
        ) : (
          <>
            {t("Invoiced —")}{' '}
            <Link to="/invoices/$invoiceId" params={{ invoiceId: order.invoice_id }} className="underline decoration-dotted">
              {t("open the invoice")}</Link>
          </>
        )}
      </li>
      <li data-testid="gate-subscription">
        {order.subscription_id === null ? (
          <span className="text-subtle">
            {t("Not provisioned — nothing starts before the invoice is paid")}</span>
        ) : (
          <>
            {order.seat === true ? t("Seat started") : t("Subscription started")} —{' '}
            <Link to="/subscription" className="underline decoration-dotted">
              {t("see it")}</Link>
          </>
        )}
      </li>
    </ol>
  );
}

function statusTone(status: Order['status']): Tone {
  switch (status) {
    case 'COMPLETED':
      return 'success';
    case 'CANCELLED':
      return 'neutral';
    case 'AWAITING_PAYMENT':
      return 'warning';
    default:
      return 'info';
  }
}
