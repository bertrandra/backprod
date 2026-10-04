import { useId, useState } from 'react';

import { t } from '@/i18n';
import { inputClass } from '@/ui/Field';
import { pill } from '@/ui/tone';
import { cn } from '@/utils/cn';
import { fromHsl, fromRgb, isHex, shades, toHsl, toRgb } from '@/utils/colour';

import { isCssValue, verdict, type Verdict } from './themeDocument';

/**
 * One colour of a palette, in one mode (2026-10-04).
 *
 * Closed, it is a swatch and its code. Open, it is every way of choosing one:
 * the system picker, the code, red/green/blue, hue/saturation/lightness, the
 * colours this palette already uses, nine shades of the current hue — and the
 * contrast of every pair this colour is part of, so a choice is measured as it
 * is made.
 *
 * A value that is not `#rrggbb` — the scrim's `rgb(… / 0.4)` — is edited as
 * text alone: a picker has no transparency to offer.
 */
export interface PairInMode {
  /** The other colour of the pair. */
  readonly with: string;
  readonly ratio: number;
}

const TONE: Record<Verdict, 'success' | 'warning' | 'danger'> = { AAA: 'success', AA: 'success', 'AA large': 'warning', fail: 'danger' };

export function verdictLabel(value: Verdict): string {
  return value === 'fail' ? t('Fails') : value === 'AA large' ? t('AA large') : value;
}

export function VerdictPill({ ratio }: { ratio: number }) {
  const level = verdict(ratio);

  return <span className={pill(TONE[level])}>{verdictLabel(level)}</span>;
}

export function ColourField({
  label,
  value,
  onChange,
  suggestions,
  pairs,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  /** Every colour the palette already uses: picking one keeps it coherent. */
  suggestions: readonly string[];
  pairs: readonly PairInMode[];
}) {
  const [open, setOpen] = useState(false);
  const panel = useId();
  const hex = isHex(value);
  const rgb = toRgb(value);
  const hsl = toHsl(value);
  const worst = pairs.length === 0 ? undefined : Math.min(...pairs.map((pair) => pair.ratio));

  return (
    <div className="min-w-0 space-y-2">
      <div className="flex min-w-0 items-center gap-1.5">
        <button
          type="button"
          aria-expanded={open}
          aria-controls={panel}
          aria-label={t('Choose {colour}', { colour: label })}
          onClick={() => setOpen((was) => !was)}
          className="size-8 shrink-0 rounded-control border border-line-strong shadow-raise focus-visible:outline-2 focus-visible:outline-offset-2"
          // The swatch is the value being edited, not a style of this screen.
          style={{ background: isCssValue(value) ? value : undefined }}
        />
        <input
          aria-label={`${label} (${t('value')})`}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className={cn(inputClass(!isCssValue(value)), 'min-w-0 font-mono text-xs')}
          spellCheck={false}
        />
        {worst !== undefined && <VerdictPill ratio={worst} />}
      </div>

      {open && (
        <div id={panel} className="space-y-3 rounded-card border border-line bg-well p-3" data-testid="colour-panel">
          {hex ? (
            <>
              <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-xs text-muted">
                  {t('Picker')}
                  <input
                    type="color"
                    aria-label={label}
                    value={value.toLowerCase()}
                    onChange={(event) => onChange(event.target.value)}
                    className="h-8 w-12 cursor-pointer rounded-control border border-line bg-surface p-0.5"
                  />
                </label>

                {rgb !== null && (
                  <fieldset className="flex items-center gap-1.5">
                    <legend className="sr-only">RGB</legend>
                    {(['r', 'g', 'b'] as const).map((key) => (
                      <label key={key} className="flex items-center gap-1 font-mono text-xs text-muted">
                        {key.toUpperCase()}
                        <input
                          type="number"
                          min={0}
                          max={255}
                          aria-label={`${label} — ${key.toUpperCase()}`}
                          value={rgb[key]}
                          onChange={(event) => onChange(fromRgb({ ...rgb, [key]: Number(event.target.value) }))}
                          className={cn(inputClass(), 'w-16 font-mono text-xs')}
                        />
                      </label>
                    ))}
                  </fieldset>
                )}
              </div>

              {hsl !== null && (
                <fieldset className="grid gap-1.5 sm:grid-cols-3">
                  <legend className="sr-only">HSL</legend>
                  {(
                    [
                      ['h', t('Hue'), 360],
                      ['s', t('Saturation'), 100],
                      ['l', t('Lightness'), 100],
                    ] as const
                  ).map(([key, name, max]) => (
                    <label key={key} className="space-y-0.5 text-xs text-muted">
                      <span className="flex justify-between">
                        <span>{name}</span>
                        <span className="font-mono">{hsl[key]}</span>
                      </span>
                      <input
                        type="range"
                        min={0}
                        max={max}
                        aria-label={`${label} — ${name}`}
                        value={hsl[key]}
                        onChange={(event) => onChange(fromHsl({ ...hsl, [key]: Number(event.target.value) }))}
                        className="w-full accent-accent"
                      />
                    </label>
                  ))}
                </fieldset>
              )}

              <Swatches title={t('Shades of this hue')} colours={shades(value)} current={value} onPick={onChange} />
            </>
          ) : (
            <p className="text-xs text-muted">{t('This value is not a six-digit code, so it is edited as text: a picker cannot hold its transparency.')}</p>
          )}

          <Swatches title={t('Already in this palette')} colours={suggestions} current={value} onPick={onChange} />

          {pairs.length > 0 && (
            <div className="space-y-1">
              <p className="text-xs font-medium text-muted">{t('Contrast')}</p>
              <ul className="flex flex-wrap gap-x-4 gap-y-1">
                {pairs.map((pair) => (
                  <li key={pair.with} className="flex items-center gap-1.5 font-mono text-2xs">
                    <span>{pair.with}</span>
                    <span>{pair.ratio.toFixed(2)}:1</span>
                    <VerdictPill ratio={pair.ratio} />
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Swatches({
  title,
  colours,
  current,
  onPick,
}: {
  title: string;
  colours: readonly string[];
  current: string;
  onPick: (value: string) => void;
}) {
  if (colours.length === 0) {
    return null;
  }

  return (
    <div className="space-y-1">
      <p className="text-xs font-medium text-muted">{title}</p>
      <div className="flex flex-wrap gap-1">
        {colours.map((colour) => (
          <button
            key={colour}
            type="button"
            title={colour}
            aria-label={t('Use {colour}', { colour })}
            aria-pressed={colour.toLowerCase() === current.toLowerCase()}
            onClick={() => onPick(colour)}
            className={cn(
              'size-6 rounded-control border focus-visible:outline-2 focus-visible:outline-offset-2',
              colour.toLowerCase() === current.toLowerCase() ? 'border-ink ring-2 ring-accent' : 'border-line-strong',
            )}
            style={{ background: colour }}
          />
        ))}
      </div>
    </div>
  );
}
