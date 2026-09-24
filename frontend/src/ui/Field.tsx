import { cloneElement, isValidElement, type ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * A labelled control with its hint and its error.
 *
 * The label is a real `<label>` bound by id, and the hint and error are
 * *attached to the control* — both are what a hand-rolled field forgets, and
 * every screen after this one inherits them by using this instead.
 *
 * **The attaching is the part that was wrong.** `aria-describedby` used to sit
 * on a `<div>` wrapping the control, where it describes the div and nothing
 * else: ARIA applies to the element carrying the attribute, so a screen reader
 * reading the input announced its label and never its hint or its error. It is
 * not a WCAG violation — nothing is missing, it is merely unreachable — so the
 * axe scan over every route had no opinion about it, and neither did the type
 * checker. Every form in the application inherited it.
 *
 * So the id is put on the control itself with `cloneElement`, merged with any
 * `aria-describedby` the caller already set. That needs the child to be a
 * single element, which every call site passes; anything else is left alone
 * rather than silently dropped.
 */
export function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string;
  label: string;
  // `| undefined` explicitly, because `exactOptionalPropertyTypes` treats an
  // absent prop and one passed as undefined as different things — and a form
  // error is naturally the second: `errors.field?.message` is undefined when the
  // field is valid.
  hint?: string | undefined;
  error?: string | undefined;
  children: ReactNode;
}) {
  const describedBy = [hint !== undefined ? `${id}-hint` : null, error !== undefined ? `${id}-error` : null]
    .filter((v) => v !== null)
    .join(' ');

  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium">
        {label}
      </label>

      {hint !== undefined && (
        <p id={`${id}-hint`} className="text-xs text-muted">
          {hint}
        </p>
      )}

      {describedBy === '' || !isValidElement<{ 'aria-describedby'?: string }>(children) ? (
        children
      ) : (
        cloneElement(children, {
          'aria-describedby': [children.props['aria-describedby'], describedBy]
            .filter((value) => value !== undefined && value !== '')
            .join(' '),
        })
      )}

      {error !== undefined && (
        <p id={`${id}-error`} role="alert" className="text-xs text-danger">
          {error}
        </p>
      )}
    </div>
  );
}

export const inputClass = (invalid = false): string =>
  cn(
    // A control reads as a control: its own surface, a hairline that darkens on
    // hover, and a focus ring that is the accent rather than the browser's
    // guess. `transition-colors` is the only motion — a field that animated its
    // size would move the label under somebody's cursor.
    'w-full rounded-control border bg-surface px-3 py-2 text-base text-ink shadow-raise transition-colors',
    'placeholder:text-subtle focus-visible:outline-2 focus-visible:outline-offset-1',
    'disabled:bg-well disabled:text-subtle disabled:shadow-none',
    invalid
      ? 'border-danger focus-visible:outline-danger'
      : 'border-line-strong hover:border-ink/25 focus-visible:border-accent',
  );

/**
 * The minimum hit area, in one place.
 *
 * ui-spec.md §4.2 sets 44px, and `Button` has always carried it — but the
 * frame's own controls are hand-rolled `<button>`s in region A rather than
 * `Button`s, and they were 26px tall on a phone. Nothing failed: axe does not
 * measure hit areas, and at desktop width nobody noticed.
 *
 * So the rule lives here now and the frame imports it. A compact control can
 * still *look* compact — the padding is unchanged — while being tall enough to
 * hit with a thumb.
 */
export const touchTargetClass = 'min-h-[44px] min-w-[44px]';

export type ButtonVariant = 'primary' | 'secondary' | 'danger';

/**
 * What a button looks like, apart from what a button *is*.
 *
 * Extracted on 2026-09-24 for the product's story, whose calls to action
 * are **links**: a product deployed beside the platform opens at its own
 * address and the prices are an anchor four bands down. Both are
 * navigations, so both are `<a>` — a `<button>` that navigates cannot be
 * opened in a new tab, middle-clicked, or copied, and reads to a screen
 * reader as the wrong kind of thing.
 *
 * So the appearance is a function and `Button` is one caller of it. The
 * alternative, an `asChild` prop cloning arbitrary children, buys the same
 * thing for more machinery and a worse type.
 */
export function buttonClass(variant: ButtonVariant = 'primary', className?: string): string {
  return cn(
    // 44px minimum, so the same control works on a phone (ui-spec.md §4.2).
    'inline-flex min-h-[44px] items-center justify-center gap-2 rounded-control px-3.5 py-2',
    'text-base font-medium whitespace-nowrap transition-[background-color,border-color,box-shadow,transform]',
    'duration-150 ease-out-quart focus-visible:outline-2 focus-visible:outline-offset-2',
    // No underline when it is a link wearing this: the shape already says
    // it can be pressed, and an underlined button reads as a mistake.
    'no-underline',
    // Pressed, not merely hovered. A control that acknowledges the press is
    // the cheapest possible signal that a write is under way, and it costs a
    // single transform.
    'active:translate-y-px disabled:translate-y-0 disabled:opacity-55 disabled:shadow-none',
    // **The primary action is the accent now, not the monochrome.** The old
    // one was `bg-neutral-900` inverting to near-white in dark, which read
    // as a print button rather than as the thing to press: on a page where
    // every border is grey, the only saturated element should be the action.
    variant === 'primary' &&
      'bg-accent text-on-accent shadow-raise hover:bg-accent-strong hover:shadow-float',
    variant === 'secondary' &&
      'border border-line-strong bg-surface text-ink shadow-raise hover:border-ink/25 hover:bg-well',
    variant === 'danger' &&
      'border border-danger/45 bg-danger-wash text-danger shadow-raise hover:border-danger',
    // Merged, and last so it wins. It used to be swallowed: `{...rest}` is
    // spread above, and the `className` below overwrote whatever a caller
    // had passed. That typechecks — `className` is part of
    // `ButtonHTMLAttributes` — and did nothing, so two screens had grown a
    // wrapper `<span>` to place a button they could not place directly.
    className,
  );
}

export function Button({
  children,
  pending = false,
  variant = 'primary',
  className,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
  pending?: boolean;
  variant?: ButtonVariant;
}) {
  return (
    <button
      {...rest}
      // Disabled while pending, because a second submit is a second write — and
      // for a mutation that allocates something, two is one too many.
      disabled={rest.disabled === true || pending}
      // The label stays put while the spinner turns, so somebody watching does
      // not lose what they pressed. That leaves the busy state invisible to a
      // screen reader unless it is said out loud, which is what this does — the
      // label used to change to "Working…" and carried it by accident.
      aria-busy={pending || undefined}
      className={buttonClass(variant, className)}
    >
      {pending && (
        <span
          aria-hidden="true"
          // Motion, not a word swap: "Working…" replaced the label, so a person
          // lost what they had pressed at the moment they most wanted to know.
          className="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      )}
      {children}
    </button>
  );
}
