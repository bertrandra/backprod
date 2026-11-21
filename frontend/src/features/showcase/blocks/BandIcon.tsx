import { PROBLEM_ICONS, type ProblemIcon } from './meta';

/**
 * One of the icons the page draws, at the one size it draws them.
 *
 * Out of `ProblemBand` since 2026-11-20, when a second band started
 * drawing from the same closed set: the stroke, the box and the weight are
 * one decision, and two copies of an `<svg>` are two that drift.
 */
export function BandIcon({ icon, className }: { icon: ProblemIcon; className?: string }) {
  return (
    <svg width="30" height="30" viewBox="0 0 30 30" aria-hidden="true" className={className}>
      <path
        d={PROBLEM_ICONS[icon]}
        fill="none"
        stroke="currentColor"
        strokeWidth="2.2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
