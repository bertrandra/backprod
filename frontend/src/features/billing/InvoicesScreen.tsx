import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useInvoices, useIssueInvoice, type Invoice } from '@/queries/billing';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `billing.invoices` — the list, and the one mutation that must never be
 * optimistic.
 *
 * **Issuing allocates a gapless legal number.** So this screen shows a real
 * pending state and then the number the database allocated — never a provisional
 * one. There is no placeholder anywhere in this file: a draft renders as "no
 * number yet", because that is what it is, and inventing something to fill the
 * gap is how a gap enters a sequence that must not have one.
 *
 * `final` rather than a status comparison decides whether anything is offered:
 * the contract defines it as "whether it can still change", and a final invoice
 * is corrected by a credit note rather than edited.
 */
export function InvoicesScreen() {
  const { data: session } = useSession();
  const invoices = useInvoices();
  const issue = useIssueInvoice();

  const [confirming, setConfirming] = useState(false);

  const mayManage = can(session, 'billing.manage');

  if (invoices.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (invoices.error !== null) {
    return <ErrorSurface error={invoices.error} onRetry={() => void invoices.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-lg font-semibold">Invoices</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {invoices.data.total} in this product
        </span>
      </div>

      {issue.error !== null && <ErrorSurface error={issue.error} />}

      {invoices.data.invoices.length === 0 ? (
        <EmptyState
          title="No invoices"
          description="An invoice is raised when an order is fulfilled, or issued here from what is billable now."
        />
      ) : (
        <ul className="space-y-2">
          {invoices.data.invoices.map((invoice) => (
            <li
              key={invoice.id}
              data-invoice={invoice.id}
              data-status={invoice.status}
              className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={`rounded px-1.5 py-0.5 text-xs font-medium ${statusClass(invoice.status)}`}
                >
                  {invoice.status}
                </span>

                <InvoiceNumber invoice={invoice} />

                <span className="ml-auto">
                  <Amount money={invoice.gross} className="font-medium" />
                </span>
              </div>

              <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                net <Amount money={invoice.net} /> · VAT <Amount money={invoice.vat} />
                {invoice.issued_at !== null &&
                  ` · issued ${new Date(invoice.issued_at).toLocaleDateString()}`}
                {invoice.due_at !== null &&
                  ` · due ${new Date(invoice.due_at).toLocaleDateString()}`}
              </p>

              <p className="mt-2 text-xs">
                <Link
                  to="/invoices/$invoiceId"
                  params={{ invoiceId: invoice.id }}
                  className="underline decoration-dotted"
                >
                  Open it
                </Link>
              </p>
            </li>
          ))}
        </ul>
      )}

      {mayManage && (
        <section className="space-y-3 border-t border-neutral-200 pt-6 dark:border-neutral-800">
          <h2 className="text-base font-semibold">Issue an invoice</h2>
          <p className="text-sm text-neutral-600 dark:text-neutral-400">
            Issuing allocates a legal number from a sequence that must have no gaps. It cannot be
            undone — a mistake is corrected by a credit note, not by deletion.
          </p>

          {confirming ? (
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                pending={issue.isPending}
                onClick={() => issue.mutate(undefined, { onSettled: () => setConfirming(false) })}
              >
                Issue it
              </Button>
              <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                Not yet
              </Button>
            </div>
          ) : (
            <Button type="button" variant="secondary" onClick={() => setConfirming(true)}>
              Issue an invoice…
            </Button>
          )}

          {issue.isPending && (
            // A real pending state, said in words. The number does not exist yet
            // and this screen will not pretend otherwise.
            <p data-testid="issuing" className="text-sm text-neutral-600 dark:text-neutral-400">
              Allocating the number…
            </p>
          )}
        </section>
      )}
    </div>
  );
}

/**
 * The number, or the honest absence of one.
 *
 * `number` is null until issued. There is no placeholder and no "pending"
 * number: what is shown is either the allocated number or the fact that none
 * exists.
 */
export function InvoiceNumber({ invoice }: { invoice: Invoice }) {
  if (invoice.number === null) {
    return (
      <span data-testid="no-number" className="text-xs text-neutral-500">
        no number yet — a draft has none
      </span>
    );
  }

  return (
    <code data-testid="invoice-number" className="text-xs">
      {invoice.number}
    </code>
  );
}

export function statusClass(status: string): string {
  switch (status) {
    case 'PAID':
      return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200';
    case 'CANCELLED':
      return 'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    case 'ISSUED':
      return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
    default:
      return 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-200';
  }
}
