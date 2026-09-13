import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * The shape of a form, once it is longer than about four fields.
 *
 * Every form in this application was one `max-w-md` column of identical
 * full-width inputs. Billing identity is nine of them; configuring a product's
 * invoicing is eleven. Two problems come from that, and neither is cosmetic:
 *
 * **Nothing says which fields belong together.** A legal name, a VAT number and
 * a registration number are one idea — who the supplier *is* — and an address is
 * another. Stacked at one weight they are nine unrelated chores, and somebody
 * filling them in has no idea how much is left or what any of it is for.
 *
 * **Every field claims the width of the longest one.** A postal code rendered as
 * wide as a legal name tells the reader to expect a legal name's worth of
 * typing. Field width is the cheapest hint a form has about what it wants, and
 * a single column throws all of it away.
 *
 * So: `FieldGroup` is a real `<fieldset>` with a real `<legend>` — which is what
 * a screen reader announces before each field in it, so the grouping is not only
 * visual — and `FieldRow` puts fields that are read together on one line, at
 * widths that mean something.
 */
export function FormCard({
  children,
  className,
  ...rest
}: { children: ReactNode; className?: string } & React.FormHTMLAttributes<HTMLFormElement>) {
  return (
    <form
      className={cn(
        'space-y-6 rounded-card border border-line bg-surface p-5 shadow-raise',
        className,
      )}
      {...rest}
    >
      {children}
    </form>
  );
}

/**
 * A named set of fields.
 *
 * `<fieldset>` and `<legend>` rather than a heading and a div, because the
 * legend is announced with each field inside it: "Where you are, Postal code"
 * rather than "Postal code" on its own, halfway down a form, with no way to tell
 * which of two addresses it belongs to.
 *
 * `<legend>` is notoriously hard to style inside a bordered fieldset, so the
 * border lives on the card and the legend is an ordinary heading-weight line.
 */
export function FieldGroup({
  legend,
  hint,
  children,
}: {
  legend: string;
  /** What the group is for, when the legend cannot say it in three words. */
  hint?: ReactNode;
  children: ReactNode;
}) {
  return (
    <fieldset className="space-y-3 border-0 p-0">
      <legend className="mb-1 text-sm font-semibold">{legend}</legend>

      {hint !== undefined && <p className="-mt-1 max-w-prose text-xs text-muted">{hint}</p>}

      {children}
    </fieldset>
  );
}

/**
 * Fields that are read as one line, on one line.
 *
 * A postal code, a city and a country are an address, and an address is not
 * three questions. They stack on a phone, where a row of three is three cramped
 * boxes.
 *
 * The widths are `basis` values rather than a fixed grid, so a two-field row and
 * a three-field row both look deliberate without either needing its own column
 * count.
 */
export function FieldRow({ children }: { children: ReactNode }) {
  // `items-end` aligns the *controls*, which is what the eye follows across a
  // row. Aligning the tops instead lets one field's hint push its input half a
  // line below its neighbours', which is exactly what a postal code beside a
  // country did. The cost is that a field showing an error lifts its control
  // while the message is on screen; a transient nudge beats a permanent stagger.
  return <div className="flex flex-wrap items-end gap-3 [&>*]:min-w-0">{children}</div>;
}

/**
 * How much room one field takes in a row.
 *
 * Named by what the field *is* rather than by a number of columns: a postal code
 * is `short` wherever it appears, and stays short if the row around it changes.
 */
export function FieldCell({
  width = 'auto',
  children,
}: {
  /** `short` a postcode or a rate · `medium` a city or a code · `auto` fills the rest. */
  width?: 'short' | 'medium' | 'auto';
  children: ReactNode;
}) {
  return (
    <div
      className={cn(
        'grow',
        width === 'short' && 'basis-28',
        width === 'medium' && 'basis-40',
        width === 'auto' && 'basis-56',
      )}
    >
      {children}
    </div>
  );
}

/**
 * Where a form's action lives, and what it says happened.
 *
 * Separated from the fields by a rule, because the last field and the button
 * that commits all of them had the same eight pixels between them as two
 * adjacent fields did.
 */
export function FormActions({
  children,
  note,
}: {
  children: ReactNode;
  /** A saved / failed message, beside the button rather than above the form. */
  note?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
      {children}
      {note}
    </div>
  );
}
