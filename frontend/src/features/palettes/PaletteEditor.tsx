import { useState } from 'react';

import { t } from '@/i18n';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { MoodBoard } from '@/ui/MoodBoard';
import { Section } from '@/ui/Page';
import { notice } from '@/ui/tone';
import { cn } from '@/utils/cn';

import { failingPairs, isCssValue, recolour, themeProperties, type ThemeDocument, type ThemeMode } from './themeDocument';

/**
 * Editing a palette (2026-10-04) — the platform administrator's alone.
 *
 * Colours in both modes, the font families, the type scale; the mood board is
 * drawn from the draft and every text pair is measured as it changes. Nothing
 * is sent until "Save", and a value that is not CSS stops the save before the
 * server would refuse it.
 */
const NAME = /^[a-z0-9][a-z0-9-]{0,62}$/;
const MODES: readonly ThemeMode[] = ['light', 'dark'];

function modeLabel(mode: ThemeMode): string {
  return mode === 'light' ? t('Light') : t('Dark');
}

export function PaletteEditor({
  initial,
  palettes,
  saving,
  saved,
  error,
  onSave,
  onClose,
}: {
  initial: { readonly name: string; readonly document: ThemeDocument };
  /** Every palette there is: the font families they carry are the ones offered. */
  palettes: readonly { readonly document: ThemeDocument }[];
  saving: boolean;
  saved: boolean;
  error: unknown;
  onSave: (name: string, document: ThemeDocument) => void;
  onClose: () => void;
}) {
  const [name, setName] = useState(initial.name);
  const [document, setDocument] = useState(initial.document);

  const validName = NAME.test(name);
  const invalidValues = document.colors.some((group) => group.tokens.some((token) => !isCssValue(token.light) || !isCssValue(token.dark)));
  const failing = failingPairs(document);
  const stripe = document.colors.flatMap((group) => group.tokens.map((token) => token.name));

  // Every font stack the palettes and this draft carry: the families the
  // platform serves. A family typed by hand would fall back silently, because
  // the page may load fonts from its own origin only.
  const stacks = (role: string) => {
    const all = [...palettes.map((palette) => palette.document), document]
      .flatMap((doc) => doc.fonts)
      .filter((font) => font.role === role);

    return [...new Map(all.map((font) => [font.stack, font])).values()];
  };

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

        <div className="grid gap-4 xl:grid-cols-2">
          {MODES.map((mode) => (
            <MoodBoard
              key={mode}
              mode={mode}
              label={modeLabel(mode)}
              scope={themeProperties(document, mode, true)}
              stripe={stripe}
              testId={`palette-preview-${mode}`}
            />
          ))}
        </div>

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

        <div className="space-y-4">
          <h3 className="text-lg font-semibold">{t('Colours')}</h3>
          {document.colors.map((group) => (
            <div key={group.group} className="space-y-2">
              <p className="text-sm font-medium text-muted">{group.group}</p>
              <ul className="grid gap-2 md:grid-cols-2">
                {group.tokens.map((token) => (
                  <li key={token.name} className="grid grid-cols-[7rem_1fr_1fr] items-center gap-2 rounded-control border border-line bg-surface px-3 py-2">
                    <span className="font-mono text-xs font-semibold">{token.name}</span>
                    {MODES.map((mode) => (
                      <ColourInput
                        key={mode}
                        label={`${token.name} — ${modeLabel(mode)}`}
                        value={token[mode]}
                        onChange={(value) => setDocument((current) => recolour(current, token.name, mode, value))}
                      />
                    ))}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="space-y-3">
          <h3 className="text-lg font-semibold">{t('Fonts')}</h3>
          <div className="grid gap-3 sm:grid-cols-2">
            {document.fonts.map((font) => (
              <Field key={font.role} id={`font-${font.role}`} label={`font-${font.role}`}>
                <select
                  id={`font-${font.role}`}
                  className={inputClass()}
                  value={font.stack}
                  onChange={(event) => {
                    const chosen = stacks(font.role).find((option) => option.stack === event.target.value);

                    if (chosen !== undefined) {
                      setDocument((current) => ({
                        ...current,
                        fonts: current.fonts.map((f) => (f.role === font.role ? chosen : f)),
                      }));
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
            ))}
          </div>
        </div>

        <div className="space-y-3">
          <h3 className="text-lg font-semibold">{t('Type scale')}</h3>
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {document.type_scale.map((step) => (
              <li key={step.name} className="grid grid-cols-[6rem_1fr] items-center gap-2 rounded-control border border-line bg-surface px-3 py-2">
                <label htmlFor={`step-${step.name}`} className="font-mono text-xs font-semibold">
                  text-{step.name}
                </label>
                <input
                  id={`step-${step.name}`}
                  className={cn(inputClass(!isCssValue(step.size)), 'font-mono text-xs')}
                  value={step.size}
                  onChange={(event) => {
                    const size = event.target.value;
                    setDocument((current) => ({
                      ...current,
                      type_scale: current.type_scale.map((s) => (s.name === step.name ? { ...s, size } : s)),
                    }));
                  }}
                />
              </li>
            ))}
          </ul>
        </div>

        <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
          <Button
            type="button"
            data-testid="save-palette"
            pending={saving}
            disabled={!validName || invalidValues}
            onClick={() => onSave(name, document)}
          >
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

/**
 * A colour as a picker and as text, side by side. The picker only speaks
 * `#rrggbb`, so a value it cannot hold — `rgb(… / 0.4)`, the scrim's — is
 * edited as text alone.
 */
function ColourInput({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  const hex = /^#[0-9a-f]{6}$/i.test(value);

  return (
    <div className="flex min-w-0 items-center gap-1.5">
      {hex && (
        <input
          type="color"
          aria-label={label}
          value={value.toLowerCase()}
          onChange={(event) => onChange(event.target.value)}
          className="h-8 w-8 shrink-0 cursor-pointer rounded-control border border-line bg-surface p-0.5"
        />
      )}
      <input
        aria-label={hex ? `${label} (${t('value')})` : label}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={cn(inputClass(!isCssValue(value)), 'min-w-0 font-mono text-xs')}
        spellCheck={false}
      />
    </div>
  );
}
