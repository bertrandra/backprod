import { useCreditNotes } from '@/queries/billing';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount, formatVatRate } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `billing.credit_notes` — documents in their own right.
 *
 * **A credit note is not an invoice status.** It has its own number, from its
 * own gapless sequence, and it corrects a final invoice rather than editing one.
 * That is why this is a screen and not a filter on the invoice list: a ledger
 * reads both documents, and collapsing one into the other would lose the
 * correction's own identity and date.
 *
 * `direction` is always `CREDIT` and the contract says why it is there at all:
 * *"so a client reading a mixed ledger never has to infer the sign"*. It is
 * shown rather than turned into a minus sign, because inferring is exactly what
 * it exists to prevent.
 */
export function CreditNotesScreen() {
  const creditNotes = useCreditNotes();

  if (creditNotes.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (creditNotes.error !== null) {
    return <ErrorSurface error={creditNotes.error} onRetry={() => void creditNotes.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-2xl font-semibold">Credit notes</h1>
        <span className="text-sm text-muted">
          {creditNotes.data.total} issued
        </span>
      </div>

      <p className="text-sm text-muted">
        A credit note corrects an invoice that is already final. Its number comes from its own
        sequence, and the invoice it corrects keeps its own number and totals.
      </p>

      {creditNotes.data.credit_notes.length === 0 ? (
        <EmptyState
          title="No credit notes"
          description="One is issued from an invoice that needs correcting."
        />
      ) : (
        <ul className="space-y-2">
          {creditNotes.data.credit_notes.map((note) => (
            <li
              key={note.id}
              data-credit-note={note.id}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <div className="flex flex-wrap items-center gap-2">
                <code data-testid="credit-note-number" className="text-xs">
                  {note.number}
                </code>
                {/* Stated, not inferred from a sign. */}
                <span
                  data-testid="direction"
                  className="rounded bg-well px-1.5 py-0.5 text-xs font-medium"
                >
                  {note.direction}
                </span>
                <span className="ml-auto">
                  <Amount money={note.gross} className="font-medium" />
                </span>
              </div>

              <p className="mt-1 text-xs text-muted">
                net <Amount money={note.net} /> · VAT <Amount money={note.vat} /> · issued{' '}
                {new Date(note.issued_at).toLocaleDateString()}
              </p>

              <p className="mt-1 text-xs text-subtle">
                corrects invoice <code>{note.invoice_id}</code>
                {note.reason !== null && ` — ${note.reason}`}
              </p>

              {note.lines.length > 0 && (
                <ul className="mt-2 space-y-1 border-t border-line pt-2 text-xs">
                  {note.lines.map((line) => (
                    <li key={line.position} className="flex flex-wrap gap-2">
                      <span className="min-w-0 flex-1 truncate">{line.description}</span>
                      <span className="text-subtle">
                        VAT {formatVatRate(line.vat_rate_basis_points)}
                      </span>
                      <Amount money={line.net} />
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
