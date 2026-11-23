import { useState } from 'react';

import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, HotspotRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * One picture, and points on it that say what is where (2026-11-23).
 *
 * The picture is the first point's — a band has no picture of its own to
 * carry, and the first row is the one the editor asks for it on. Each
 * point is a numbered button at its place; choosing one shows what it says
 * beside the picture, or under it on a phone. The same text is a list for
 * anybody who does not point at pictures, so nothing is only on hover.
 */
export function HotspotBand({ rows, heading }: { rows: readonly ShowcaseRow<HotspotRow>[]; heading?: BandHeading | undefined }) {
  const [chosen, setChosen] = useState(0);
  const picture = rows[0]?.image ?? null;

  if (rows.length === 0 || picture === null) {
    return null;
  }

  const current = rows[chosen] ?? rows[0];

  return (
    <ShowcaseBand
      id={BAND_META.HOTSPOT.anchor}
      surface={BAND_META.HOTSPOT.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Look closer")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-hotspot"
    >
      <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-center">
        <div className="relative overflow-hidden rounded-card border border-line">
          <img src={picture.url} alt={picture.alt} className="block w-full" />
          {rows.map((row, index) => (
            <button
              key={row.id}
              type="button"
              data-hotspot={row.id}
              aria-pressed={index === chosen}
              aria-label={row.content.title}
              onClick={() => setChosen(index)}
              style={{ left: `${String(row.content.x)}%`, top: `${String(row.content.y)}%` }}
              className={cn(
                'absolute flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-surface text-sm font-semibold shadow-float transition-transform',
                'focus-visible:outline-2 focus-visible:outline-offset-2',
                index === chosen ? 'scale-110 bg-accent text-on-accent' : 'bg-surface text-ink hover:scale-110',
              )}
            >
              {index + 1}
            </button>
          ))}
        </div>

        {current !== undefined && (
          <div aria-live="polite" data-testid="hotspot-said" className="space-y-2">
            <p className="text-sm font-semibold text-accent">{chosen + 1} / {rows.length}</p>
            <h3 className="display-type text-xl font-semibold">{current.content.title}</h3>
            {current.content.body !== null && <p className="text-base text-muted">{current.content.body}</p>}
          </div>
        )}
      </div>

      <ol className="sr-only">
        {rows.map((row) => (
          <li key={row.id}>
            {row.content.title}
            {row.content.body !== null && ` — ${row.content.body}`}
          </li>
        ))}
      </ol>
    </ShowcaseBand>
  );
}
