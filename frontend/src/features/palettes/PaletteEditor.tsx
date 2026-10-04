import { useState } from 'react';

import { t } from '@/i18n';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { MoodBoard } from '@/ui/MoodBoard';
import { Section } from '@/ui/Page';
import { Tabs } from '@/ui/Tabs';
import { notice, pill } from '@/ui/tone';
import { cn } from '@/utils/cn';
import { isHex } from '@/utils/colour';

import { differences, keeps, tokenState, worstPairs, type ColourFilter, type DesignSystemColours, type TokenState } from './colourList';
import { ColourField, VerdictPill, type PairInMode } from './ColourField';
import {
  PAIRS,
  failingPairs,
  isCssValue,
  pairRatio,
  recolour,
  themeCss,
  themeProperties,
  type ThemeDocument,
  type ThemeMode,
} from './themeDocument';
import { roleOf } from './tokenRoles';

/**
 * Designing a palette (2026-10-04) — the platform administrator's alone.
 *
 * Four tabs over one draft, and the preview beside them whichever is open:
 *
 *   - **Palette** — every colour in both modes, each with a picker, its code,
 *     RGB and HSL, the colours the palette already uses, the shades of its
 *     hue, and the contrast of every pair it is part of. Since 2026-10-05,
 *     after Plan's palette screen, each token also says what it paints, the
 *     list filters to what changed or what fails contrast and searches by
 *     name, variable or role, and every row says its state in words;
 *   - **Fonts** — the families, and the type scale step by step;
 *   - **CSS** — the stylesheet the draft amounts to, to read or copy;
 *   - **Contrast** — every pair text is set in, measured in both modes, and a
 *     checker for any two colours.
 *
 * Nothing is sent until "Save", and a value that is not CSS stops the save
 * before the server would refuse it.
 */
const NAME = /^[a-z0-9][a-z0-9-]{0,62}$/;
const MODES: readonly ThemeMode[] = ['light', 'dark'];

type TabId = 'palette' | 'fonts' | 'css' | 'contrast';

function modeLabel(mode: ThemeMode): string {
  return mode === 'light' ? t('Light') : t('Dark');
}

export function PaletteEditor({
  initial,
  palettes,
  origin = {},
  saving,
  saved,
  error,
  onSave,
  onClose,
}: {
  initial: { readonly name: string; readonly document: ThemeDocument };
  /** Every palette there is: the font families they carry are the ones offered. */
  palettes: readonly { readonly document: ThemeDocument }[];
  /** The design system's own colours, so a row can say it has moved away from them. */
  origin?: DesignSystemColours;
  saving: boolean;
  saved: boolean;
  error: unknown;
  onSave: (name: string, document: ThemeDocument) => void;
  onClose: () => void;
}) {
  const [name, setName] = useState(initial.name);
  const [document, setDocument] = useState(initial.document);
  const [tab, setTab] = useState<TabId>('palette');
  const [previewMode, setPreviewMode] = useState<ThemeMode>('light');

  const validName = NAME.test(name);
  const invalidValues =
    document.colors.some((group) => group.tokens.some((token) => !isCssValue(token.light) || !isCssValue(token.dark))) ||
    document.type_scale.some((step) => !isCssValue(step.size));
  const failing = failingPairs(document);

  return (
    <Section
      title={t('Editing {name}', { name: initial.name })}
      description={t('Changes are a draft until you save. Every organisation wearing this palette changes with it at its next page load.')}
      data-testid="palette-editor"
    >
      <div className="space-y-6">
        <div className="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2">
          <Field
            id="palette-name"
            label={t('Name')}
            hint={t('Lower-case letters, digits and hyphens. A new name makes a new palette.')}
            error={validName ? undefined : t('Use lower-case letters, digits and hyphens.')}
          >
            <input id="palette-name" className={cn(inputClass(!validName), 'font-mono')} value={name} onChange={(event) => setName(event.target.value)} spellCheck={false} autoComplete="off" />
          </Field>
          <Field id="palette-label" label={t('Label')} hint={t('What the palette is called, for people.')}>
            <input
              id="palette-label"
              className={inputClass()}
              value={document.label ?? ''}
              maxLength={60}
              onChange={(event) => {
                const label = event.target.value;
                // An emptied label is no label, not an empty one: the
                // server takes 1 to 60 characters or none.
                setDocument((current) => {
                  const rest = { ...current };
                  delete rest.label;

                  return label.trim() === '' ? rest : { ...rest, label };
                });
              }}
            />
          </Field>
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:items-start">
          <Tabs
            label={t('Palette design')}
            selected={tab}
            onSelect={setTab}
            testId="palette-tabs"
            tabs={[
              { id: 'palette', label: t('Palette') },
              { id: 'fonts', label: t('Fonts') },
              { id: 'css', label: 'CSS' },
              { id: 'contrast', label: t('Contrast') },
            ]}
          >
            {tab === 'palette' && <ColoursTab document={document} saved={initial.document} origin={origin} onChange={setDocument} />}
            {tab === 'fonts' && <FontsTab document={document} palettes={palettes} onChange={setDocument} />}
            {tab === 'css' && <CssTab document={document} />}
            {tab === 'contrast' && <ContrastTab document={document} />}
          </Tabs>

          {/* The preview stays whichever tab is open, and follows the draft as
              it changes: what is being designed is always in view. */}
          <aside className="space-y-3 xl:sticky xl:top-4" aria-label={t('Preview')} data-testid="palette-preview">
            <div className="flex items-center justify-between gap-3">
              <h3 className="text-lg font-semibold">{t('Preview')}</h3>
              <div className="flex gap-1" role="group" aria-label={t('Mode')}>
                {MODES.map((mode) => (
                  <button
                    key={mode}
                    type="button"
                    aria-pressed={previewMode === mode}
                    onClick={() => setPreviewMode(mode)}
                    className={cn(
                      'rounded-control border px-2.5 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2',
                      previewMode === mode ? 'border-accent bg-accent-wash text-accent-strong' : 'border-line bg-surface text-muted',
                    )}
                  >
                    {modeLabel(mode)}
                  </button>
                ))}
              </div>
            </div>

            <MoodBoard
              mode={previewMode}
              label={modeLabel(previewMode)}
              scope={themeProperties(document, previewMode, true)}
              stripe={document.colors.flatMap((group) => group.tokens.map((token) => token.name))}
              testId={`palette-preview-${previewMode}`}
            />

            {failing.length === 0 ? (
              <p className={notice('success')} data-testid="palette-contrast" role="status">
                {t('Every text pair clears WCAG AA, in both modes.')}
              </p>
            ) : (
              <div className={notice('warning')} data-testid="palette-contrast" role="status">
                <p className="font-medium">{t('Some text would be hard to read. WCAG AA asks 4.5:1:')}</p>
                <ul className="mt-1 list-disc pl-5">
                  {failing.map((pair) => (
                    <li key={`${pair.mode}-${pair.foreground}-${pair.background}`} className="font-mono text-xs">
                      {modeLabel(pair.mode)} · {pair.foreground} / {pair.background} · {pair.ratio.toFixed(2)}:1
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </aside>
        </div>

        <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
          <Button type="button" data-testid="save-palette" pending={saving} disabled={!validName || invalidValues} onClick={() => onSave(name, document)}>
            {t('Save the palette')}
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('Close')}
          </Button>
          {saved && (
            <span role="status" className="text-xs text-success" data-testid="palette-saved">
              {t('Saved.')}
            </span>
          )}
        </div>
        {error !== null && error !== undefined && <ErrorSurface error={error} />}
      </div>
    </Section>
  );
}

type Change = (update: (current: ThemeDocument) => ThemeDocument) => void;

/** The pairs a colour is part of, in one mode, measured against the other colour. */
function pairsOf(document: ThemeDocument, name: string, mode: ThemeMode): readonly PairInMode[] {
  return PAIRS.flatMap(([fg, bg]) => {
    const other = fg === name ? bg : bg === name ? fg : null;
    const ratio = other === null ? undefined : pairRatio(document, fg, bg, mode);

    return other === null || ratio === undefined ? [] : [{ with: other, ratio }];
  });
}

const FILTERS: readonly { readonly id: ColourFilter; readonly label: string }[] = [
  { id: 'all', label: 'All' },
  { id: 'changed', label: 'Changed' },
  { id: 'contrast', label: 'Failing contrast' },
];

function stateLabel(state: TokenState): string | undefined {
  if (state === null) {
    return undefined;
  }

  return state.kind === 'contrast'
    ? t('Contrast {ratio}:1', { ratio: state.pair.ratio.toFixed(2) })
    : state.kind === 'unsaved' ? t('Not saved') : t('Changed from the design system');
}

function ColoursTab({
  document,
  saved,
  origin,
  onChange,
}: {
  document: ThemeDocument;
  /** The palette as it was opened: what "not saved" is measured against. */
  saved: ThemeDocument;
  origin: DesignSystemColours;
  onChange: Change;
}) {
  const [filter, setFilter] = useState<ColourFilter>('all');
  const [search, setSearch] = useState('');
  // Every distinct code the draft already uses, in both modes.
  const used = [...new Set(document.colors.flatMap((group) => group.tokens.flatMap((token) => [token.light, token.dark])).filter(isHex).map((c) => c.toLowerCase()))];
  const worst = worstPairs(failingPairs(document));
  const rows = document.colors.flatMap((group) =>
    group.tokens.map((token) => ({
      group: group.group,
      token,
      role: roleOf(token.name),
      state: tokenState(token.name, document, saved, origin, worst),
      facts: { failing: worst.has(token.name), changed: Object.values(differences(token.name, document, saved, origin)).some(Boolean) },
    })),
  );
  const count: Record<ColourFilter, number> = {
    all: rows.length,
    changed: rows.filter((row) => row.facts.changed).length,
    contrast: rows.filter((row) => row.facts.failing).length,
  };
  const shown = rows.filter((row) => keeps(filter, search, row.token, row.role, row.facts));

  return (
    <div className="space-y-5" data-testid="palette-colours">
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1" role="group" aria-label={t('Filter the colours')}>
          {FILTERS.map((option) => (
            <button
              key={option.id}
              type="button"
              aria-pressed={filter === option.id}
              data-testid={`colour-filter-${option.id}`}
              onClick={() => setFilter(option.id)}
              className={cn(
                'rounded-control border px-2.5 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2',
                filter === option.id ? 'border-accent bg-accent-wash text-accent-strong' : 'border-line bg-surface text-muted',
              )}
            >
              {t(option.label)} <span className="tabular-nums">{count[option.id]}</span>
            </button>
          ))}
        </div>
        <input
          type="search"
          className={cn(inputClass(), 'min-w-0 flex-1 sm:max-w-xs')}
          placeholder={t('Search a colour or what it paints')}
          aria-label={t('Search a colour or what it paints')}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>

      {shown.length === 0 && (
        <p className="text-sm text-muted" data-testid="colour-list-empty">
          {filter === 'changed'
            ? t('No colour has changed: this is the palette as saved, and as the design system has it.')
            : filter === 'contrast'
              ? t('Every pair clears contrast.')
              : t('No colour matches “{search}”.', { search })}
        </p>
      )}

      {document.colors.map((group) => {
        const lines = shown.filter((row) => row.group === group.group);

        return lines.length === 0 ? null : (
          <div key={group.group} className="space-y-2">
            <h4 className="text-sm font-medium text-muted">{group.group}</h4>
            <ul className="space-y-2">
              {lines.map(({ token, role, state }) => (
                <li key={token.name} className="space-y-2 rounded-control border border-line bg-surface p-3" data-token={token.name}>
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="font-mono text-xs font-semibold">
                      {token.name} <span className="font-normal text-muted">{token.variable}</span>
                    </p>
                    {state !== null && (
                      <span
                        className={pill(state.kind === 'contrast' ? 'danger' : state.kind === 'unsaved' ? 'warning' : 'info')}
                        data-testid={`token-state-${token.name}`}
                        title={
                          state.kind === 'contrast'
                            ? `${modeLabel(state.pair.mode)} · ${state.pair.foreground} / ${state.pair.background}`
                            : undefined
                        }
                      >
                        {stateLabel(state)}
                      </span>
                    )}
                  </div>
                  {role !== undefined && <p className="text-xs text-muted">{role}</p>}
                  <div className="grid gap-3 sm:grid-cols-2">
                    {MODES.map((mode) => (
                      <div key={mode} className="space-y-1">
                        <p className="text-2xs font-medium uppercase text-muted">{modeLabel(mode)}</p>
                        <ColourField
                          label={`${token.name} — ${modeLabel(mode)}`}
                          value={token[mode]}
                          onChange={(value) => onChange((current) => recolour(current, token.name, mode, value))}
                          suggestions={used}
                          pairs={pairsOf(document, token.name, mode)}
                        />
                      </div>
                    ))}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        );
      })}
    </div>
  );
}

function FontsTab({
  document,
  palettes,
  onChange,
}: {
  document: ThemeDocument;
  palettes: readonly { readonly document: ThemeDocument }[];
  onChange: Change;
}) {
  // Every font stack the palettes and this draft carry: the families the
  // platform serves. A family typed by hand would fall back silently, because
  // the page may load fonts from its own origin only.
  const stacks = (role: string) => {
    const all = [...palettes.map((palette) => palette.document), document].flatMap((doc) => doc.fonts).filter((font) => font.role === role);

    return [...new Map(all.map((font) => [font.stack, font])).values()];
  };

  const step = (name: string, field: 'size' | 'line_height' | 'letter_spacing', value: string) =>
    onChange((current) => ({
      ...current,
      type_scale: current.type_scale.map((s) =>
        s.name !== name ? s : field === 'size' ? { ...s, size: value } : { ...s, [field]: value.trim() === '' ? null : value },
      ),
    }));

  return (
    <div className="space-y-6" data-testid="palette-fonts">
      <div className="grid gap-3 sm:grid-cols-2">
        {document.fonts.map((font) => (
          <div key={font.role} className="space-y-2 rounded-control border border-line bg-surface p-3">
            <Field id={`font-${font.role}`} label={`font-${font.role}`}>
              <select
                id={`font-${font.role}`}
                className={inputClass()}
                value={font.stack}
                onChange={(event) => {
                  const chosen = stacks(font.role).find((option) => option.stack === event.target.value);

                  if (chosen !== undefined) {
                    onChange((current) => ({ ...current, fonts: current.fonts.map((f) => (f.role === font.role ? chosen : f)) }));
                  }
                }}
              >
                {stacks(font.role).map((option) => (
                  <option key={option.stack} value={option.stack}>
                    {option.family}
                  </option>
                ))}
              </select>
            </Field>
            {/* Set in the stack being chosen: the specimen is the value. */}
            <p className="text-xl break-words" style={{ fontFamily: font.stack }}>
              Aa Bb Cc 0123456789 €
            </p>
            <p className="font-mono text-2xs break-words text-muted">{font.stack}</p>
          </div>
        ))}
      </div>

      <div className="space-y-2">
        <h4 className="text-sm font-medium text-muted">{t('Type scale')}</h4>
        <ul className="divide-y divide-line rounded-card border border-line bg-surface">
          {document.type_scale.map((s) => (
            <li key={s.name} className="grid gap-2 p-3 sm:grid-cols-[7rem_repeat(3,minmax(0,1fr))] sm:items-center">
              <span className="font-mono text-xs font-semibold">text-{s.name}</span>
              {(
                [
                  ['size', t('Size'), s.size],
                  ['line_height', t('Line height'), s.line_height ?? ''],
                  ['letter_spacing', t('Letter spacing'), s.letter_spacing ?? ''],
                ] as const
              ).map(([field, label, value]) => (
                <input
                  key={field}
                  aria-label={`text-${s.name} — ${label}`}
                  placeholder={label}
                  value={value}
                  onChange={(event) => step(s.name, field, event.target.value)}
                  className={cn(inputClass(value !== '' && !isCssValue(value)), 'font-mono text-xs')}
                />
              ))}
              <p
                className="overflow-hidden break-words sm:col-span-4"
                style={{
                  fontFamily: document.fonts.find((font) => font.role === 'sans')?.stack,
                  ...(isCssValue(s.size) && { fontSize: s.size }),
                  ...(s.line_height !== null && isCssValue(s.line_height) && { lineHeight: s.line_height }),
                  ...(s.letter_spacing !== null && isCssValue(s.letter_spacing) && { letterSpacing: s.letter_spacing }),
                }}
              >
                {t('One seat, billed monthly')}
              </p>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function CssTab({ document }: { document: ThemeDocument }) {
  const [copied, setCopied] = useState<'css' | 'json' | null>(null);
  const css = themeCss(document);
  const json = JSON.stringify(document, null, 2);

  const copy = (what: 'css' | 'json', text: string) => {
    void navigator.clipboard?.writeText(text).then(() => setCopied(what));
  };

  return (
    <div className="space-y-4" data-testid="palette-css">
      <p className="text-sm text-muted">
        {t('What the shell sets for an organisation wearing this palette: the light values, fonts and type scale on :root, and the dark values in the colour-scheme query.')}
      </p>
      {(
        [
          ['css', 'CSS', css],
          ['json', 'JSON', json],
        ] as const
      ).map(([what, title, text]) => (
        <div key={what} className="space-y-1">
          <div className="flex items-center justify-between gap-2">
            <h4 className="text-sm font-medium">{title}</h4>
            <div className="flex items-center gap-2">
              {copied === what && (
                <span role="status" className="text-xs text-success">
                  {t('Copied.')}
                </span>
              )}
              <Button type="button" variant="secondary" onClick={() => copy(what, text)}>
                {t('Copy {what}', { what: title })}
              </Button>
            </div>
          </div>
          <pre
            tabIndex={0}
            data-testid={`palette-${what}-text`}
            className="max-h-96 overflow-auto rounded-control border border-line bg-well p-3 font-mono text-2xs text-ink"
          >
            {text}
          </pre>
        </div>
      ))}
    </div>
  );
}

function ContrastTab({ document }: { document: ThemeDocument }) {
  const names = document.colors.flatMap((group) => group.tokens.map((token) => token.name));
  const [fg, setFg] = useState(names.includes('ink') ? 'ink' : (names[0] ?? ''));
  const [bg, setBg] = useState(names.includes('canvas') ? 'canvas' : (names[1] ?? ''));

  const sample = (foreground: string, background: string, mode: ThemeMode) => {
    const ratio = pairRatio(document, foreground, background, mode);
    const scope = themeProperties(document, mode, true);

    return (
      <div className="flex flex-wrap items-center gap-2">
        <span
          className="rounded-control border border-line px-2 py-0.5 font-semibold"
          style={{ ...scope, color: `var(--color-${foreground})`, backgroundColor: `var(--color-${background})` }}
        >
          Aa
        </span>
        <span className="font-mono text-xs">{ratio === undefined ? '—' : `${ratio.toFixed(2)}:1`}</span>
        {ratio !== undefined && <VerdictPill ratio={ratio} />}
      </div>
    );
  };

  return (
    <div className="space-y-6" data-testid="palette-contrast-tab">
      <div className="space-y-3 rounded-card border border-line bg-surface p-4">
        <h4 className="text-sm font-medium">{t('Check any two colours')}</h4>
        <div className="grid gap-3 sm:grid-cols-2">
          {(
            [
              ['contrast-fg', t('Text'), fg, setFg],
              ['contrast-bg', t('Background'), bg, setBg],
            ] as const
          ).map(([id, label, value, set]) => (
            <Field key={id} id={id} label={label}>
              <select id={id} className={inputClass()} value={value} onChange={(event) => set(event.target.value)}>
                {names.map((name) => (
                  <option key={name} value={name}>
                    {name}
                  </option>
                ))}
              </select>
            </Field>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-2" data-testid="contrast-check">
          {MODES.map((mode) => (
            <div key={mode} className="space-y-1">
              <p className="text-2xs font-medium uppercase text-muted">{modeLabel(mode)}</p>
              {sample(fg, bg, mode)}
            </div>
          ))}
        </div>
        <p className="text-xs text-muted">{t('WCAG asks 4.5:1 for text (AA), 3:1 for large text, and 7:1 for AAA.')}</p>
      </div>

      <div className="rounded-card border border-line bg-surface">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-line text-left text-xs text-muted">
              <th scope="col" className="px-3 py-2 font-medium">
                {t('Text on ground')}
              </th>
              {MODES.map((mode) => (
                <th key={mode} scope="col" className="px-3 py-2 font-medium">
                  {modeLabel(mode)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {PAIRS.map(([foreground, background]) => (
              <tr key={`${foreground}/${background}`} className="border-b border-line last:border-b-0" data-pair={`${foreground}/${background}`}>
                <th scope="row" className="px-3 py-2 text-left font-mono text-xs font-normal">
                  {foreground} / {background}
                </th>
                {MODES.map((mode) => (
                  <td key={mode} className="px-3 py-2">
                    {sample(foreground, background, mode)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
