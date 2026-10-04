import { useState, type CSSProperties } from 'react';

import { PAIRS } from '@/features/palettes/themeDocument';
import { t } from '@/i18n';
import stylesheet from '@/index.css?raw';
import { usePalettes, useSavePalette } from '@/queries/palettes';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { PageHeader, Section } from '@/ui/Page';
import { pill, type Tone } from '@/ui/tone';
import { MoodBoard } from '@/ui/MoodBoard';
import { When } from '@/ui/When';
import { cn } from '@/utils/cn';

import {
  contrast,
  readPalette,
  themeDocument,
  type Palette,
  type PaletteFont,
  type PaletteTextStep,
  type PaletteToken,
  type PaletteValue,
  type Theme,
} from './palette';

/**
 * `/console/palette` — the design system's colours, as `src/index.css` writes
 * them (2026-10-04).
 *
 * A developer's view, and a read-only one: the place to change a colour is the
 * stylesheet, and this screen is what that change looks like. It owns no colour
 * of its own. The mood board is drawn with the semantic utilities and nothing
 * else, scoped to each theme by the variables {@see readPalette} lifted out of
 * the file; the structure below it is the file's own groups, comments and line
 * numbers. A token added there is here at the next build — there is no list in
 * this module to forget to update, which is the failure a palette page usually
 * has.
 *
 * **Both themes at once**, whichever the browser prefers, because a value is
 * only half-chosen until its dark counterpart is seen beside it.
 *
 * **And it saves what it read as a palette** (2026-10-04): the colours, the
 * fonts and the type scale as one JSON document, under `default` unless told
 * otherwise, through `savePalette`. Organisations can then wear it like any
 * other palette; `/console/palettes` assigns and edits it.
 */

const PALETTE: Palette = readPalette(stylesheet);

/** What this build's stylesheet says, in the shape the platform stores. */
const DOCUMENT = themeDocument(PALETTE);

/** The name the stylesheet is saved under unless somebody types another. */
const DEFAULT_NAME = 'default';

const THEMES: readonly Theme[] = ['light', 'dark'];

/** Called at render, not at load, so the words follow the language chosen. */
function themeLabel(theme: Theme): string {
  return theme === 'light' ? t('Light') : t('Dark');
}

const STRIPE = PALETTE.groups.flatMap((group) => group.tokens.map((token) => token.utility));

const TOKENS = new Map(PALETTE.groups.flatMap((group) => group.tokens.map((token) => [token.utility, token] as const)));

function scoped(theme: Theme): CSSProperties {
  return { ...PALETTE.scope[theme], colorScheme: theme };
}

export function PaletteScreen() {
  const count = PALETTE.groups.reduce((total, group) => total + group.tokens.length, 0);

  return (
    <div className="max-w-6xl space-y-10">
      <PageHeader
        title={t('Palette')}
        meta={t('{count} colours in {groups} groups', { count, groups: PALETTE.groups.length })}
        description={t(
          'The design system’s colours, read from src/index.css when the console was built. Change a value there and this screen follows: nothing on it is typed twice.',
        )}
      />

      <Section
        title={t('Mood board')}
        description={t('The palette in use, in both themes, drawn only with the semantic utilities.')}
      >
        <div className="grid gap-6 xl:grid-cols-2">
          {THEMES.map((theme) => (
            <MoodBoard
              key={theme}
              mode={theme}
              label={themeLabel(theme)}
              scope={scoped(theme)}
              stripe={STRIPE}
              testId={`mood-board-${theme}`}
            />
          ))}
        </div>
      </Section>

      <Section
        title={t('Structure')}
        description={t(
          'Each group is a run of --color-* in @theme, titled by the comment above it. A utility reads a --ds-* variable, and each theme defines that variable once.',
        )}
      >
        <div className="space-y-8">
          {PALETTE.groups.map((group) => (
            <div key={group.title} className="space-y-3">
              <div className="space-y-1">
                <h3 className="text-lg font-semibold">{group.title}</h3>
                {group.description !== '' && <p className="max-w-prose text-sm text-muted">{group.description}</p>}
              </div>

              <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {group.tokens.map((token) => (
                  <TokenCard key={token.utility} token={token} />
                ))}
              </ul>
            </div>
          ))}

          {PALETTE.unmapped.length > 0 && (
            <div className="space-y-3" data-testid="palette-unmapped">
              <div className="space-y-1">
                <h3 className="text-lg font-semibold">{t('Not mapped to a utility')}</h3>
                <p className="max-w-prose text-sm text-muted">
                  {t('Defined on :root and named by no --color-* in @theme, so no class can reach it.')}
                </p>
              </div>
              <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {PALETTE.unmapped.map((entry) => (
                  <TokenCard
                    key={entry.variable}
                    token={{ utility: entry.variable, variable: entry.variable, line: entry.light?.line ?? 0, light: entry.light, dark: entry.dark }}
                  />
                ))}
              </ul>
            </div>
          )}
        </div>
      </Section>

      <Section
        title={t('Typography')}
        description={t(
          'The families and the type scale @theme defines, each set in its own values. A screen picks a step; it never writes a size.',
        )}
      >
        <div className="space-y-6">
          <ul className="grid gap-3 md:grid-cols-2">
            {PALETTE.fonts.map((font) => (
              <FontCard key={font.role} font={font} />
            ))}
          </ul>

          <ul className="divide-y divide-line rounded-card border border-line bg-surface">
            {PALETTE.typeScale.map((step) => (
              <TextStepRow key={step.name} step={step} />
            ))}
          </ul>
        </div>
      </Section>

      <Section
        title={t('Pairings')}
        description={t(
          'Ink on the grounds it is set on, measured from the values above. WCAG AA asks 4.5:1 for text and 3:1 for large text; the accessibility scan enforces it on every screen.',
        )}
      >
        <Pairings />
      </Section>

      <Section
        title={t('Save as a palette')}
        description={t(
          'What this screen read from the stylesheet, saved as a palette — default unless you type another name. Organisations can then wear it: assign it and edit it on the Palettes screen.',
        )}
      >
        <SaveAsPalette />
      </Section>
    </div>
  );
}

function Swatch({ value, theme }: { value: PaletteValue | undefined; theme: Theme }) {
  return (
    <span
      className="block h-full flex-1"
      data-theme={theme}
      style={value === undefined ? undefined : { backgroundColor: value.value }}
    />
  );
}

function TokenCard({ token }: { token: PaletteToken }) {
  return (
    <li className="overflow-hidden rounded-card border border-line bg-surface shadow-raise" data-testid={`token-${token.utility}`}>
      <div className="flex h-16 border-b border-line" aria-hidden="true">
        <Swatch value={token.light} theme="light" />
        <Swatch value={token.dark} theme="dark" />
      </div>

      <div className="space-y-2 p-3">
        <div className="flex items-baseline justify-between gap-2">
          <span className="font-mono text-sm font-semibold">{token.utility}</span>
          <span className="font-mono text-2xs text-muted">{token.variable}</span>
        </div>

        <dl className="grid grid-cols-[auto_1fr_auto] gap-x-3 gap-y-0.5 font-mono text-xs">
          {THEMES.map((theme) => {
            const value = token[theme];

            return (
              <div key={theme} className="contents">
                <dt className="text-muted">{themeLabel(theme)}</dt>
                <dd>{value?.value ?? '—'}</dd>
                <dd className="text-right text-muted">{value === undefined ? '' : `index.css:${value.line}`}</dd>
              </div>
            );
          })}
        </dl>

        {token.light?.note !== undefined && <p className="text-xs text-muted">{token.light.note}</p>}
      </div>
    </li>
  );
}

function verdict(ratio: number | undefined): { tone: Tone; label: string } {
  if (ratio === undefined) {
    return { tone: 'neutral', label: t('Unknown') };
  }

  if (ratio >= 4.5) {
    return { tone: 'success', label: 'AA' };
  }

  return ratio >= 3 ? { tone: 'warning', label: t('AA large') } : { tone: 'danger', label: t('Fails') };
}

function Pairings() {
  const pairs = PAIRS.flatMap(([fg, bg]) => {
    const foreground = TOKENS.get(fg);
    const background = TOKENS.get(bg);

    return foreground === undefined || background === undefined ? [] : [{ fg, bg, foreground, background }];
  });

  return (
    // Cells wrap rather than the table scrolling: a phone gets every pair on
    // screen, and a scroll region would be one more thing to reach by keyboard.
    <div className="rounded-card border border-line bg-surface">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-line text-left text-xs text-muted">
            <th className="px-3 py-2 font-medium">{t('Text on ground')}</th>
            {THEMES.map((theme) => (
              <th key={theme} className="px-3 py-2 font-medium">
                {themeLabel(theme)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {pairs.map(({ fg, bg, foreground, background }) => (
            <tr key={`${fg}/${bg}`} className="border-b border-line last:border-b-0" data-testid={`pair-${fg}-${bg}`}>
              <td className="px-3 py-2 font-mono text-xs">
                {fg} / {bg}
              </td>
              {THEMES.map((theme) => {
                const ratio =
                  foreground[theme] === undefined || background[theme] === undefined
                    ? undefined
                    : contrast(foreground[theme].value, background[theme].value);
                const { tone, label } = verdict(ratio);

                return (
                  <td key={theme} className="px-3 py-2">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                      {/* Only the sample takes the theme; the verdict beside it
                          stays in the page's own, where it is read. */}
                      <span
                        className="rounded-control border border-line px-2 py-0.5 font-semibold"
                        style={{
                          ...scoped(theme),
                          color: `var(--color-${foreground.utility})`,
                          backgroundColor: `var(--color-${background.utility})`,
                        }}
                      >
                        Aa
                      </span>
                      <span className="font-mono text-xs">{ratio === undefined ? '—' : `${ratio.toFixed(2)}:1`}</span>
                      <span className={pill(tone)}>{label}</span>
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function FontCard({ font }: { font: PaletteFont }) {
  return (
    <li className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise" data-testid={`font-${font.role}`}>
      <div className="flex items-baseline justify-between gap-2">
        <span className="font-mono text-sm font-semibold">font-{font.role}</span>
        <span className="font-mono text-2xs text-muted">
          {font.variable} · index.css:{font.line}
        </span>
      </div>

      {/* Set in the stack the file gives, not in a class: the specimen is the
          value being shown. */}
      <div style={{ fontFamily: font.stack }} className="space-y-1">
        <p className="text-display-sm font-semibold">{font.family}</p>
        <p className="text-lg break-words">Aa Bb Cc Dd Ee Ff Gg Hh Ii Jj Kk Ll Mm</p>
        <p className="text-lg break-words">0123456789 € % — ’ « »</p>
      </div>

      <p className="font-mono text-2xs break-words text-muted">{font.stack}</p>
    </li>
  );
}

function TextStepRow({ step }: { step: PaletteTextStep }) {
  return (
    <li className="grid gap-2 p-3 sm:grid-cols-[10rem_1fr] sm:items-baseline" data-testid={`text-${step.name}`}>
      <div className="font-mono text-xs">
        <p className="font-semibold">text-{step.name}</p>
        <p className="text-muted">
          {step.size}
          {step.lineHeight !== undefined && ` / ${step.lineHeight}`}
          {step.letterSpacing !== undefined && ` · ${step.letterSpacing}`}
        </p>
        <p className="text-muted">index.css:{step.line}</p>
      </div>
      <p
        className="overflow-hidden break-words font-semibold"
        style={{
          fontSize: step.size,
          ...(step.lineHeight !== undefined && { lineHeight: step.lineHeight }),
          ...(step.letterSpacing !== undefined && { letterSpacing: step.letterSpacing }),
        }}
      >
        {t('One seat, billed monthly')}
      </p>
    </li>
  );
}

/**
 * Saving what the screen read, under a name — `default` until somebody types
 * another. Says whether the copy already saved under that name is the
 * stylesheet as this build has it, so a save that would change nothing reads
 * as one.
 */
function SaveAsPalette() {
  const [name, setName] = useState(DEFAULT_NAME);
  const palettes = usePalettes();
  const save = useSavePalette();
  const valid = /^[a-z0-9][a-z0-9-]{0,62}$/.test(name);
  const saved = palettes.data?.find((palette) => palette.name === name);

  // Both are the same shape in the same key order — the server returns a
  // document in the order it was written — so their text is comparable.
  const current = saved !== undefined && JSON.stringify(saved.document) === JSON.stringify(DOCUMENT);

  return (
    <div className="space-y-3 rounded-card border border-line bg-surface p-4">
      <Field
        id="palette-name"
        label={t('Name')}
        hint={t('Lower-case letters, digits and hyphens. Saving under an existing palette’s name replaces it for everyone wearing it.')}
        error={valid ? undefined : t('Use lower-case letters, digits and hyphens.')}
      >
        <input
          id="palette-name"
          className={cn(inputClass(!valid), 'max-w-xs font-mono')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          spellCheck={false}
          autoComplete="off"
        />
      </Field>

      <div className="flex flex-wrap items-center gap-3">
        <Button
          type="button"
          data-testid="save-as-palette"
          pending={save.isPending}
          disabled={!valid || palettes.isPending}
          onClick={() => save.mutate({ name, document: DOCUMENT })}
        >
          {t('Save as {name}', { name })}
        </Button>

        <span className="text-xs text-muted" data-testid="palette-state" role="status">
          {!valid || palettes.isPending ? null : saved === undefined ? (
            t('No palette has this name yet.')
          ) : (
            <>
              {current ? t('Saved, and the same as the stylesheet.') : t('Saved, and different from the stylesheet.')}{' '}
              <When at={saved.updated_at} />
            </>
          )}
        </span>
      </div>

      {save.error !== null && <ErrorSurface error={save.error} />}
      {palettes.error !== null && <ErrorSurface error={palettes.error} onRetry={() => void palettes.refetch()} />}

      <details className="rounded-control border border-line bg-well">
        <summary className="cursor-pointer px-3 py-2 text-sm font-medium">{t('The JSON that is saved')}</summary>
        <pre className="max-h-96 overflow-auto px-3 pb-3 font-mono text-2xs text-muted" tabIndex={0} data-testid="palette-json">
          {JSON.stringify(DOCUMENT, null, 2)}
        </pre>
      </details>
    </div>
  );
}
