import type { CSSProperties, ReactNode } from 'react';

import { t } from '@/i18n';
import { pill, type Tone } from '@/ui/tone';
import { cn } from '@/utils/cn';

/**
 * The palette in use (2026-10-04): an invoice on its ground, the accent, the
 * selection, the primary action, the three inks and the five tones — drawn
 * with the semantic utilities and nothing else, so it shows whichever theme
 * its `scope` sets. The console draws the stylesheet with it; the theme editor
 * draws the document being edited.
 */
/** A caption naming the tokens a tile is made of, in the tile's own ink. */
function Tokens({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={cn('font-mono text-2xs', className ?? 'text-muted')}>{children}</p>;
}

function Tile({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('flex flex-col justify-between gap-4 rounded-card p-4', className)}>{children}</div>;
}

export function MoodBoard({
  mode,
  label,
  scope,
  stripe,
  testId,
}: {
  mode: 'light' | 'dark';
  label: string;
  /** The custom properties the board is drawn with — one theme, one mode. */
  scope: CSSProperties;
  /** Every colour utility, in order, for the swatch strip. */
  stripe: readonly string[];
  testId?: string;
}) {
  return (
    <figure
      data-testid={testId}
      // The family is read again here, not inherited: `body` resolved
      // `var(--font-sans)` once, and its children inherit the result — so a
      // scope that sets `--font-sans` would otherwise change nothing.
      style={{ ...scope, colorScheme: mode, fontFamily: 'var(--font-sans)' }}
      className="space-y-3 rounded-card border border-line bg-canvas p-4 text-ink"
    >
      <figcaption className="flex items-baseline justify-between gap-3">
        <span className="text-sm font-semibold">{label}</span>
        <span className="font-mono text-2xs text-muted">prefers-color-scheme: {mode}</span>
      </figcaption>

      {/* Every colour once, in the file's order: the board's swatch strip. */}
      <div className="flex h-8 overflow-hidden rounded-control border border-line" aria-hidden="true">
        {stripe.map((utility) => (
          <span key={utility} title={utility} className="flex-1" style={{ backgroundColor: `var(--color-${utility})` }} />
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
        <Tile className="col-span-2 row-span-2 border border-line bg-canvas sm:col-span-4">
          <div className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-float">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="font-mono text-2xs uppercase text-subtle">{t('Invoice')}</p>
                <p className="text-xl font-semibold">2026-000142</p>
                <p className="text-sm text-muted">{t('One seat, billed monthly')}</p>
              </div>
              <span className={pill('success')}>{t('Paid')}</span>
            </div>
            <div className="flex items-baseline justify-between rounded-control border border-line bg-well px-3 py-2">
              <span className="text-sm text-muted">{t('Total')}</span>
              <span className="font-mono text-lg font-semibold">€ 24.00</span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="rounded-control bg-inverse px-3 py-1.5 text-sm font-medium text-on-inverse">
                {t('Download')}
              </span>
              <span className="rounded-control border border-line-strong bg-raised px-3 py-1.5 text-sm font-medium shadow-raise">
                {t('Send again')}
              </span>
              <span className="text-sm font-medium text-accent underline">{t('Open the subscription')}</span>
            </div>
          </div>
          <Tokens>canvas · surface · well · raised · line · line-strong · shadow-float</Tokens>
        </Tile>

        <Tile className="col-span-1 bg-accent text-on-accent sm:col-span-2">
          <p className="text-display-sm font-semibold">Aa</p>
          <Tokens className="text-on-accent">accent · on-accent</Tokens>
        </Tile>

        <Tile className="col-span-1 border border-line bg-accent-wash text-accent-strong sm:col-span-2">
          <p className="text-lg font-semibold">{t('Selected')}</p>
          <Tokens className="text-accent-strong">accent-wash · accent-strong</Tokens>
        </Tile>

        <Tile className="col-span-1 bg-inverse text-on-inverse sm:col-span-2">
          <p className="text-lg font-semibold">{t('Primary action')}</p>
          <Tokens className="text-on-inverse">inverse · on-inverse</Tokens>
        </Tile>

        <Tile className="col-span-1 border border-line bg-surface sm:col-span-2">
          <div className="space-y-0.5">
            <p className="text-lg font-semibold text-ink">{t('What is read')}</p>
            <p className="text-sm text-muted">{t('What explains it')}</p>
            <p className="text-sm text-subtle">{t('What is there when looked for')}</p>
          </div>
          <Tokens>ink · muted · subtle</Tokens>
        </Tile>

        <Tile className="col-span-2 border border-line bg-surface sm:col-span-2">
          <div className="flex flex-wrap gap-1.5">
            {(['success', 'warning', 'danger', 'info', 'neutral'] as const satisfies readonly Tone[]).map((tone) => (
              <span key={tone} className={pill(tone)}>
                {tone}
              </span>
            ))}
          </div>
          <Tokens>success · warning · danger · info (+ -wash)</Tokens>
        </Tile>
      </div>
    </figure>
  );
}
