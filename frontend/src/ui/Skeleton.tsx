import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * A shape where content will be, not a spinner.
 *
 * A spinner says "something is happening"; a skeleton says "a list is coming
 * and it will be about this tall", which stops the page jumping when it
 * arrives. `prefers-reduced-motion` removes the pulse rather than the shape.
 */
export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn(
        'rounded bg-well motion-safe:animate-pulse',
        className,
      )}
    />
  );
}

/** A stand-in for a list, announced to assistive technology as busy. */
export function SkeletonRows({ rows = 5 }: { rows?: number }) {
  return (
    <div role="status" aria-busy="true" aria-live="polite" className="space-y-2">
      <span className="sr-only">{t("Loading…")}</span>
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  );
}
