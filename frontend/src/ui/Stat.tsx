import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * The headline figures a dashboard leads with.
 *
 * The metrics screen opened on three tables and no answer: to learn what a
 * product earned last month you read down a column of twelve rows and found the
 * last settled one yourself. A table is the right form for *seven columns of
 * audit detail* and the wrong one for *one number somebody came to see* — so the
 * numbers come first and the tables stay underneath, unchanged, as what the
 * figures are drawn from.
 *
 * **Proportional figures here, tabular in the tables.** `body` sets
 * `tabular-nums` because this platform puts money in columns, and that is right
 * in a column and wrong at 30 px: every digit gets the width of a `0`, so `121`
 * renders visibly loose. A standalone figure asks for the font's own spacing.
 */
export function StatTile({
  label,
  value,
  detail,
  trend,
  state,
}: {
  label: ReactNode;
  value: ReactNode;
  /** What the figure is of — the month, the window. Never a second number. */
  detail?: ReactNode;
  /** An optional sparkline, drawn by `Sparkline` below. */
  trend?: ReactNode;
  /** A pill or note qualifying the figure: "still moving", "not measured". */
  state?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 shadow-raise">
      <span className="text-2xs font-semibold uppercase text-subtle">{label}</span>

      <span className="text-3xl font-semibold [font-variant-numeric:proportional-nums]">
        {value}
      </span>

      {trend}

      {/* The state belongs to the period, so it sits beside the period. It was
          in the header first, where a two-word label and a two-word pill both
          wrapped and the tile led with its caveat instead of its number. */}
      {(detail !== undefined || state !== undefined) && (
        <div className="mt-auto flex flex-wrap items-center gap-2 pt-0.5">
          {detail !== undefined && <span className="text-xs text-muted">{detail}</span>}
          {state}
        </div>
      )}
    </div>
  );
}

/** A row of tiles that reflows rather than scrolling sideways on a phone. */
export function StatRow({ children }: { children: ReactNode }) {
  return <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div>;
}

/**
 * Twelve months of shape, and no axis.
 *
 * A sparkline answers one question — *which way, and how steadily* — and it
 * answers it in the corner of a tile where a chart with axes would not fit. It
 * is deliberately not a chart: no gridlines, no ticks, no tooltip. The figure
 * above it is the value; this is only its shape, and the exact numbers are in
 * the table below, which is the accessible view of the same data.
 *
 * Drawn as plain SVG rather than with a charting library: one would be the
 * largest dependency in the bundle, for eleven line segments.
 */
export function Sparkline({
  values,
  label,
  className,
}: {
  /** Oldest first. Absent points are `null` and break the line rather than reading as zero. */
  values: readonly (number | null)[];
  /** What the shape is of, for a reader who cannot see it. */
  label: string;
  className?: string;
}) {
  const known = values.filter((value): value is number => value !== null);

  // Nothing to draw a shape from. One point is a dot, not a trend.
  if (known.length < 2) {
    return null;
  }

  const width = 120;
  const height = 28;
  const pad = 2;

  const min = Math.min(...known);
  const max = Math.max(...known);
  // A flat series would divide by zero; it draws as a centred straight line.
  const span = max - min;

  const x = (index: number): number =>
    values.length === 1 ? pad : pad + (index * (width - pad * 2)) / (values.length - 1);

  const y = (value: number): number =>
    span === 0
      ? height / 2
      : height - pad - ((value - min) * (height - pad * 2)) / span;

  // Segments rather than one path: a gap in the data has to be a gap in the
  // line, and a single `d` string would either skip it or draw through it.
  const segments: string[] = [];
  let current: string[] = [];

  values.forEach((value, index) => {
    if (value === null) {
      if (current.length > 1) {
        segments.push(current.join(' '));
      }

      current = [];

      return;
    }

    current.push(`${current.length === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(value).toFixed(1)}`);
  });

  if (current.length > 1) {
    segments.push(current.join(' '));
  }

  // Annotated, because `reduce` otherwise infers the accumulator from the
  // array's own `number | null` and the index stops being an index.
  const lastIndex = values.reduce<number>(
    (found, value, index) => (value === null ? found : index),
    -1,
  );
  const last = lastIndex === -1 ? null : values[lastIndex];

  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${String(width)} ${String(height)}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-full', className)}
    >
      {segments.map((d) => (
        <path
          key={d}
          d={d}
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          strokeLinecap="round"
          strokeLinejoin="round"
          className="text-line-strong"
          vectorEffect="non-scaling-stroke"
        />
      ))}

      {/* The period being reported, in the accent — the rest is context. */}
      {typeof last === 'number' && (
        <circle
          cx={x(lastIndex)}
          cy={y(last)}
          r={3}
          className="fill-accent"
          vectorEffect="non-scaling-stroke"
        />
      )}
    </svg>
  );
}
