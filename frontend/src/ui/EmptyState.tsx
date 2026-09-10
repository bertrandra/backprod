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
    <div className="grid place-items-center rounded-lg border border-dashed border-neutral-300 p-10 text-center dark:border-neutral-700">
      <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{title}</p>
      {description !== undefined && (
        <p className="mt-1 max-w-prose text-sm text-neutral-600 dark:text-neutral-400">
          {description}
        </p>
      )}
      {action !== undefined && <div className="mt-4">{action}</div>}
    </div>
  );
}
