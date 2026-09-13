import type { ReactNode, ThHTMLAttributes, TdHTMLAttributes } from 'react';

import { cn } from '@/utils/cn';

/**
 * A table, and the three things every one of them got wrong separately.
 *
 * **Numbers were left-aligned.** Six screens put money in a column and aligned
 * it with the prose beside it, so €9.00 and €104.40 did not share a decimal
 * point and a column of figures could not be scanned down. On a billing
 * platform that is the column people actually read.
 *
 * **The header was the same weight as the body.** `text-xs uppercase` in subtle
 * ink, and nothing separating it from the first row, so a long table lost its
 * header the moment it scrolled.
 *
 * **A table at 375 px pushed the page sideways.** Each screen wrapped its own
 * `overflow-x-auto` — or forgot to, and then the whole application scrolled
 * horizontally instead of the table.
 *
 * So the scroll container is part of the table rather than something a screen
 * remembers, the header sticks to the top of that container, and a column says
 * `numeric` once instead of every cell repeating an alignment.
 */
export function Table({
  children,
  caption,
  className,
}: {
  children: ReactNode;
  /**
   * What this table is, for somebody who cannot see it laid out.
   *
   * Visually hidden rather than absent: a screen reader announces a table by
   * its caption, and "table with 7 columns" is what it says without one. The
   * heading above is not enough — it is not attached to the table.
   */
  caption?: ReactNode;
  className?: string;
}) {
  return (
    // `-mx-*` then `px-*`: the scroll region runs to the edge of the content
    // column so a wide table does not appear to be cut off inside a card, while
    // the first and last cells keep their gutter.
    <div className="-mx-1 overflow-x-auto px-1">
      <table className={cn('w-full min-w-max text-sm', className)}>
        {caption !== undefined && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  );
}

export function THead({ children }: { children: ReactNode }) {
  return (
    <thead className="sticky top-0 z-10 bg-surface">
      <tr className="border-b border-line-strong">{children}</tr>
    </thead>
  );
}

export function TBody({ children }: { children: ReactNode }) {
  // `divide-y` rather than a border on each row: the last row then has no rule
  // under it, which is what stops a table from looking like it was cut off.
  return <tbody className="divide-y divide-line">{children}</tbody>;
}

/**
 * A row. `state` tints it for the one case a table needs to distinguish —
 * a row that is no longer in force (a retired product, a cancelled order).
 */
export function TR({
  children,
  muted,
  ...rest
}: { children: ReactNode; muted?: boolean } & React.HTMLAttributes<HTMLTableRowElement>) {
  return (
    <tr
      className={cn('transition-colors hover:bg-well/60', muted === true && 'text-muted')}
      {...rest}
    >
      {children}
    </tr>
  );
}

/**
 * A column heading. `numeric` right-aligns it *and* its column's cells have to
 * say so too — the alignment lives on each cell because a table has no column
 * element to hang it from.
 */
export function Th({
  children,
  numeric,
  className,
  ...rest
}: { children: ReactNode; numeric?: boolean } & ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cn(
        'py-2 pr-4 text-2xs font-semibold uppercase text-subtle last:pr-0',
        numeric === true ? 'text-right' : 'text-left',
        className,
      )}
      {...rest}
    >
      {children}
    </th>
  );
}

export function Td({
  children,
  numeric,
  className,
  ...rest
}: { children: ReactNode; numeric?: boolean } & TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cn(
        'py-2 pr-4 align-middle last:pr-0',
        // `tabular-nums` is already on `body`, and this is the column it was put
        // there for: a figure only aligns with the one above it when every digit
        // is the same width.
        numeric === true && 'text-right tabular-nums',
        className,
      )}
      {...rest}
    >
      {children}
    </td>
  );
}
