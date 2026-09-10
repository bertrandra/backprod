import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * A labelled control with its error.
 *
 * The label is a real `<label>` bound by id, and the error is announced — both
 * are the things a hand-rolled field forgets, and every screen after this one
 * inherits them by using this instead.
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
        <p id={`${id}-hint`} className="text-xs text-neutral-600 dark:text-neutral-400">
          {hint}
        </p>
      )}

      <div aria-describedby={describedBy === '' ? undefined : describedBy}>{children}</div>

      {error !== undefined && (
        <p id={`${id}-error`} role="alert" className="text-xs text-red-700 dark:text-red-400">
          {error}
        </p>
      )}
    </div>
  );
}

export const inputClass = (invalid = false): string =>
  cn(
    'w-full rounded border px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-neutral-950',
    invalid
      ? 'border-red-400 dark:border-red-700'
      : 'border-neutral-300 dark:border-neutral-700',
  );

export function Button({
  children,
  pending = false,
  variant = 'primary',
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
  pending?: boolean;
  variant?: 'primary' | 'secondary' | 'danger';
}) {
  return (
    <button
      {...rest}
      // Disabled while pending, because a second submit is a second write — and
      // for a mutation that allocates something, two is one too many.
      disabled={rest.disabled === true || pending}
      className={cn(
        // 44px minimum, so the same control works on a phone (ui-spec.md §4.2).
        'min-h-[44px] rounded px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-60',
        variant === 'primary' && 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900',
        variant === 'secondary' && 'border border-neutral-300 dark:border-neutral-700',
        variant === 'danger' && 'border border-red-400 text-red-800 dark:text-red-300',
      )}
    >
      {pending ? 'Working…' : children}
    </button>
  );
}
