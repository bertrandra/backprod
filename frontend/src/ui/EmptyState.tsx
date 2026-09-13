import type { ReactNode } from 'react';

/**
 * Nothing here, and why.
 *
 * Distinct from an error on purpose. "You have no invoices yet" and "invoices
 * could not be loaded" look identical if both render as an empty table, and a
 * person who cannot tell them apart will either wait for data that is not
 * coming or report a fault that does not exist.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    // A surface, not a dashed outline. A dashed box reads as a drop target or as
    // something unfinished; an empty state is neither — it is a place with
    // nothing in it yet, and saying so calmly is the whole job.
    <div className="grid place-items-center rounded-card border border-line bg-surface px-8 py-14 text-center shadow-raise">
      <p className="text-lg font-semibold text-ink">{title}</p>
      {description !== undefined && (
        <p className="mt-2 max-w-[46ch] text-base text-muted">{description}</p>
      )}
      {action !== undefined && <div className="mt-5">{action}</div>}
    </div>
  );
}
