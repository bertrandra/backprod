import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';

import { can } from '@/app/access/access';
import {
  isRefundable,
  isRetryable,
  usePayments,
  useRefundPayment,
  useRetryPayment,
  type StartedPayment,
  type Payment,
} from '@/queries/payments';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { When } from '@/ui/When';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';
import { Whose } from '@/ui/Whose';
import { t } from '@/i18n';

/**
 * `billing.payments` — and the retry that is a **new attempt**.
 *
 * A failed payment stays failed. Retrying creates a fresh attempt against the
 * same invoice with a fresh credential; it does not revive the old one, and the
 * old one keeps its failure code because it is the record of what happened. The
 * screen says all of that in words, because somebody who believes their previous
 * attempt is resuming will not understand why the card is asked for again.
 *
 * **The new credential is never stored.** `retryPayment` returns a
 * `client_secret` "here and nowhere else", short-lived; it is handed to the
 * provider's SDK and forgotten. Nothing on this screen writes it to storage, to
 * a URL, or to the query cache.
 *
 * A refund needs a **reason**, which the API stores as a category — so the
 * categories are offered rather than a free-text box that produces forty
 * spellings of the same word.
 */
const REFUND_REASONS = ['DUPLICATE', 'FRAUDULENT', 'REQUESTED_BY_CUSTOMER', 'ERROR'] as const;

export function PaymentsScreen() {
  const { data: session } = useSession();
  const payments = usePayments();
  const retry = useRetryPayment();
  const refund = useRefundPayment();

  const [refunding, setRefunding] = useState<string | null>(null);
  const [reason, setReason] = useState<string>(REFUND_REASONS[0]);
  // The new attempt, held for exactly as long as this render, so the card
  // form is offered where the secret was born (ADR-048).
  const [retried, setRetried] = useState<{ of: string; started: StartedPayment } | null>(null);

  const mayManage = can(session, 'payments.manage');
  // Retrying a failed payment is paying (2026-09-18); refunding is not.
  const mayPay = can(session, 'billing.pay');

  if (payments.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (payments.error !== null) {
    return <ErrorSurface error={payments.error} onRetry={() => void payments.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader
        title={t("Payments")}
        meta={<>{payments.data.total} {t("recorded")}</>}
      />

      {retry.error !== null && <ErrorSurface error={retry.error} />}
      {refund.error !== null && <ErrorSurface error={refund.error} />}

      {payments.data.payments.length === 0 ? (
        <EmptyState
          title={t("No payments")}
          description={t("A payment is taken against an invoice, or by a checkout.")}
        />
      ) : (
        <ul className="space-y-2">
          {payments.data.payments.map((payment) => (
            <li
              key={payment.id}
              data-payment={payment.id}
              data-status={payment.status}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={pill(statusTone(payment.status))}
                >
                  {payment.status.replace(/_/g, ' ')}
                </span>
                <span className="text-xs text-subtle">{payment.provider}</span>
                {payment.method !== null && (
                  <span className="text-xs text-subtle">{payment.method}</span>
                )}
                <span className="ml-auto">
                  <Amount money={payment.amount} className="font-medium" />
                </span>
              </div>

              {/* The facts of the attempt, with their times (2026-09-19): when
                  it was started, when it settled or failed, by what, and
                  the invoice it collects. */}
              <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2" data-testid="payment-details">
                <div className="flex gap-2">
                  <dt className="text-subtle">{t("Started")}</dt>
                  <dd><When at={payment.created_at} testId="payment-started" /></dd>
                </div>
                {payment.succeeded_at !== null && (
                  <div className="flex gap-2">
                    <dt className="text-subtle">{t("Succeeded")}</dt>
                    <dd><When at={payment.succeeded_at} testId="payment-succeeded" /></dd>
                  </div>
                )}
                {payment.failed_at !== null && (
                  <div className="flex gap-2">
                    <dt className="text-subtle">{t("Failed")}</dt>
                    <dd><When at={payment.failed_at} testId="payment-failed-at" /></dd>
                  </div>
                )}
                <div className="flex gap-2">
                  <dt className="text-subtle">{t("By")}</dt>
                  <dd>
                    {payment.method ?? t("not recorded")} {t("via")}{' '}{payment.provider}
                  </dd>
                </div>
                <div className="flex gap-2">
                  <dt className="text-subtle">{t("For")}</dt>
                  <dd>
                    <Link to="/invoices/$invoiceId" params={{ invoiceId: payment.invoice_id }} className="underline decoration-dotted">
                      {/* The document's own number where it has one, and
                          "the invoice" while it is still a draft. Never a
                          placeholder: a number comes from a gapless sequence
                          at issue, and inventing one is how a hole enters it. */}
                      {payment.invoice_number ?? t("the invoice")}
                    </Link>
                    {payment.subscription_id !== null && (
                      <>
                        {' · '}
                        <Link to="/subscription" className="underline decoration-dotted">
                          {t("the subscription")}</Link>
                      </>
                    )}
                  </dd>
                </div>
                {/* Whose it is (2026-09-26). `billing.manage` shows an
                    administrator every payment the organisation has, and
                    until this none of the rows said which colleague each one
                    belonged to — twelve identical amounts read as twelve
                    identical rows. From the invoice's snapshot, so it names
                    who they were when the document was raised (§25). */}
                <div className="flex gap-2 sm:col-span-2">
                  <dt className="text-subtle">{t("Whose")}</dt>
                  <dd>
                    <Whose
                      name={payment.customer_name}
                      email={payment.customer_email}
                      testId={`payment-whose-${payment.id}`}
                      className="text-xs text-muted"
                    />
                  </dd>
                </div>
                <div className="flex gap-2 sm:col-span-2">
                  <dt className="text-subtle">{t("Reference")}</dt>
                  {/* The provider's own handle: what support quotes to them. */}
                  <dd className="select-all font-mono text-[11px]">{payment.provider_payment_id}</dd>
                </div>
              </dl>

              <Failure payment={payment} />

              {(mayManage || mayPay) && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {isRetryable(payment) && mayPay && (
                    <Button
                      type="button"
                      pending={retry.isPending}
                      onClick={() =>
                        retry.mutate(payment.id, {
                          // The secret goes to the provider's SDK and nowhere
                          // else: it is held only until the form below has
                          // been through, and never written anywhere.
                          onSuccess: (started) => setRetried({ of: payment.id, started }),
                        })
                      }
                    >
                      {t("Try again")}</Button>
                  )}

                  {isRefundable(payment) && mayManage && refunding !== payment.id && (
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => setRefunding(payment.id)}
                    >
                      {t("Refund…")}</Button>
                  )}
                </div>
              )}

              {retried?.of === payment.id && (
                // Said explicitly. This attempt is still failed; a different one
                // has begun, and its card form is right here.
                <div className="mt-2 space-y-2">
                  <p data-testid="new-attempt" className="text-xs text-muted">
                    A <strong>{t("new")}</strong> {t("attempt has started. This one stays failed — it is the record of what happened — and the card details are asked for again because the previous attempt cannot be resumed.")}</p>
                  <PaymentElementPanel
                    provider={retried.started.payment_provider}
                    clientSecret={retried.started.client_secret}
                    amount={retried.started.amount}
                    returnUrl={window.location.href}
                    onSettled={() => {
                      setRetried(null);
                      void payments.refetch();
                    }}
                  />
                </div>
              )}

              {refunding === payment.id && (
                <div className="mt-3 max-w-md space-y-3">
                  <Field
                    id={`reason-${payment.id}`}
                    label={t("Reason")}
                    hint={t("Stored as a category, so it is chosen rather than typed.")}
                  >
                    <select
                      id={`reason-${payment.id}`}
                      className={inputClass()}
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                    >
                      {REFUND_REASONS.map((option) => (
                        <option key={option} value={option}>
                          {option.toLowerCase().replace(/_/g, ' ')}
                        </option>
                      ))}
                    </select>
                  </Field>

                  <p className="text-xs text-muted">
                    {t("The whole refundable amount is returned. The money leaves asynchronously, so this is accepted rather than done.")}</p>

                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      variant="danger"
                      pending={refund.isPending}
                      onClick={() =>
                        refund.mutate(
                          { paymentId: payment.id, reason },
                          { onSettled: () => setRefunding(null) },
                        )
                      }
                    >
                      {t("Refund it")}</Button>
                    <Button type="button" variant="secondary" onClick={() => setRefunding(null)}>
                      {t("Cancel")}</Button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Why it failed.
 *
 * Both the code and the reason, because they answer different questions: the
 * code is what to quote to the provider, the reason is what to tell the person.
 */
function Failure({ payment }: { payment: Payment }) {
  if (payment.failure_code === null && payment.failure_reason === null) {
    return null;
  }

  return (
    <p data-testid="failure" className="mt-1 text-xs text-danger">
      {payment.failure_reason ?? t("The attempt failed.")}
      {payment.failure_code !== null && (
        <span className="text-subtle"> ({payment.failure_code})</span>
      )}
    </p>
  );
}

function statusTone(status: Payment['status']): Tone {
  switch (status) {
    case 'SUCCEEDED':
      return 'success';
    case 'FAILED':
      return 'danger';
    case 'REFUNDED':
    case 'CHARGED_BACK':
      return 'neutral';
    default:
      return 'warning';
  }
}
