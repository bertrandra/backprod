import { useState } from 'react';

import { can } from '@/app/access/access';
import {
  isRefundable,
  isRetryable,
  usePayments,
  useRefundPayment,
  useRetryPayment,
  type Payment,
} from '@/queries/payments';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

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
  const [retried, setRetried] = useState<string | null>(null);

  const mayManage = can(session, 'payments.manage');

  if (payments.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (payments.error !== null) {
    return <ErrorSurface error={payments.error} onRetry={() => void payments.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-lg font-semibold">Payments</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {payments.data.total} recorded
        </span>
      </div>

      {retry.error !== null && <ErrorSurface error={retry.error} />}
      {refund.error !== null && <ErrorSurface error={refund.error} />}

      {payments.data.payments.length === 0 ? (
        <EmptyState
          title="No payments"
          description="A payment is taken against an invoice, or by a checkout."
        />
      ) : (
        <ul className="space-y-2">
          {payments.data.payments.map((payment) => (
            <li
              key={payment.id}
              data-payment={payment.id}
              data-status={payment.status}
              className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={`rounded px-1.5 py-0.5 text-xs font-medium ${statusClass(payment.status)}`}
                >
                  {payment.status.replace(/_/g, ' ')}
                </span>
                <span className="text-xs text-neutral-500">{payment.provider}</span>
                {payment.method !== null && (
                  <span className="text-xs text-neutral-500">{payment.method}</span>
                )}
                <span className="ml-auto">
                  <Amount money={payment.amount} className="font-medium" />
                </span>
              </div>

              <Failure payment={payment} />

              {mayManage && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {isRetryable(payment) && (
                    <Button
                      type="button"
                      pending={retry.isPending}
                      onClick={() =>
                        retry.mutate(payment.id, {
                          // The secret goes to the provider's SDK and nowhere
                          // else. What is remembered here is only *that* a new
                          // attempt was started, so the screen can say so.
                          onSuccess: () => setRetried(payment.id),
                        })
                      }
                    >
                      Try again
                    </Button>
                  )}

                  {isRefundable(payment) && refunding !== payment.id && (
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => setRefunding(payment.id)}
                    >
                      Refund…
                    </Button>
                  )}
                </div>
              )}

              {retried === payment.id && (
                // Said explicitly. This attempt is still failed; a different one
                // has begun.
                <p data-testid="new-attempt" className="mt-2 text-xs text-neutral-700 dark:text-neutral-300">
                  A <strong>new</strong> attempt has started. This one stays failed — it is the
                  record of what happened — and the card details are asked for again because the
                  previous attempt cannot be resumed.
                </p>
              )}

              {refunding === payment.id && (
                <div className="mt-3 max-w-md space-y-3">
                  <Field
                    id={`reason-${payment.id}`}
                    label="Reason"
                    hint="Stored as a category, so it is chosen rather than typed."
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

                  <p className="text-xs text-neutral-600 dark:text-neutral-400">
                    The whole refundable amount is returned. The money leaves asynchronously, so
                    this is accepted rather than done.
                  </p>

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
                      Refund it
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setRefunding(null)}>
                      Cancel
                    </Button>
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
    <p data-testid="failure" className="mt-1 text-xs text-red-700 dark:text-red-300">
      {payment.failure_reason ?? 'The attempt failed.'}
      {payment.failure_code !== null && (
        <span className="text-neutral-500"> ({payment.failure_code})</span>
      )}
    </p>
  );
}

function statusClass(status: Payment['status']): string {
  switch (status) {
    case 'SUCCEEDED':
      return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200';
    case 'FAILED':
      return 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200';
    case 'REFUNDED':
    case 'CHARGED_BACK':
      return 'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    default:
      return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
  }
}
