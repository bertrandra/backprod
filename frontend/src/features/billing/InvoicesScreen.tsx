import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useInvoices, useIssueInvoice, type Invoice } from '@/queries/billing';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { lineOfferLabel } from '@/ui/LineOffer';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';

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
      <PageHeader
        title={'Invoices'}
        meta={<>{invoices.data.total} in this product</>}
      />

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
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={pill(statusTone(invoice.status))}
                >
                  {invoice.status}
                </span>

                <InvoiceNumber invoice={invoice} />

                <span className="ml-auto">
                  <Amount money={invoice.gross} className="font-medium" />
                </span>
              </div>

              {lineOfferLabel(invoice.lines) !== '' && (
                <p className="mt-1 text-sm" data-testid="invoice-what">
                  {lineOfferLabel(invoice.lines)}
                </p>
              )}

              <p className="mt-1 text-xs text-muted">
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
        <section className="space-y-3 border-t border-line pt-6">
          <h2 className="text-xl font-semibold">Issue an invoice</h2>
          <p className="text-sm text-muted">
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
            <p data-testid="issuing" className="text-sm text-muted">
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
      <span data-testid="no-number" className="text-xs text-subtle">
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

/**
 * An invoice's four states, each named.
 *
 * `DRAFT` was reaching the `default` arm and coming out blue — the tone this
 * palette reserves for something in flight. A draft is the opposite: nothing has
 * been issued, nothing is owed, and nobody is waiting. Naming it also empties
 * the default arm, which is why the default is now the colourless one: an
 * invoice status this screen has never heard of should look like a fact it
 * cannot interpret, not like one it can.
 */
export function statusTone(status: string): Tone {
  switch (status) {
    case 'PAID':
      return 'success';
    case 'ISSUED':
      return 'warning';
    case 'DRAFT':
    case 'CANCELLED':
    default:
      return 'neutral';
  }
}
