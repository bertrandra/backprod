import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * The top of a screen, in one shape.
 *
 * Forty-one screens each wrote their own. Three wrappers were in use —
 * `<header className="space-y-1">`, `<div className="space-y-1">` and a
 * `flex items-baseline` row — and which one a screen got depended on whether it
 * had a count beside the title on the day it was written. So the title sat on a
 * different baseline from one screen to the next, and a count that belonged
 * beside the title on Orders sat under it on Invoices.
 *
 * The parts are named by what they are rather than where they go, which is what
 * lets the arrangement change once rather than forty-one times:
 *
 *   - `title` — what this screen is. Always an `<h1>`; there is one per screen.
 *   - `meta` — a fact about the thing on screen, sitting on the title's baseline:
 *     "6 in this product", a status pill. Not a sentence.
 *   - `description` — why this screen exists and what its refusals mean. Prose,
 *     and the place this application says what the reader could not guess.
 *   - `actions` — what can be done from here, pushed to the far end.
 *
 * `description` is deliberately not truncated or collapsed. On a platform whose
 * screens refuse for reasons a reader cannot infer — an invoice that needs an
 * issuer, a version frozen at publication — the paragraph *is* the feature.
 */
export function PageHeader({
  title,
  meta,
  description,
  actions,
  className,
}: {
  title: ReactNode;
  meta?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <header className={cn('space-y-1.5', className)}>
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 className="text-2xl font-semibold">{title}</h1>

        {meta !== undefined && <span className="text-sm text-muted">{meta}</span>}

        {/* `ml-auto` on a baseline row rather than a second flex container: an
            action and the title then share one line on a wide screen and wrap
            to their own on a narrow one, with no breakpoint to maintain. */}
        {actions !== undefined && (
          <div className="ml-auto flex flex-wrap items-center gap-2">{actions}</div>
        )}
      </div>

      {description !== undefined && (
        <p className="max-w-prose text-sm text-muted">{description}</p>
      )}
    </header>
  );
}

/**
 * A titled division within a screen.
 *
 * The same three parts one level down, so a section heading is never a bare
 * `<h2>` with a paragraph guessing at its own spacing.
 */
export function Section({
  title,
  meta,
  description,
  actions,
  children,
  className,
  ...rest
}: {
  title?: ReactNode;
  meta?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
} & Omit<React.HTMLAttributes<HTMLElement>, 'title'>) {
  return (
    <section className={cn('space-y-3', className)} {...rest}>
      {title !== undefined && (
        <div className="space-y-1">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 className="text-xl font-semibold">{title}</h2>

            {meta !== undefined && <span className="text-sm text-muted">{meta}</span>}

            {actions !== undefined && (
              <div className="ml-auto flex flex-wrap items-center gap-2">{actions}</div>
            )}
          </div>

          {description !== undefined && (
            <p className="max-w-prose text-sm text-muted">{description}</p>
          )}
        </div>
      )}

      {children}
    </section>
  );
}
