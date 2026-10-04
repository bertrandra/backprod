import { useState } from 'react';

import { t } from '@/i18n';
import {
  useSaveTenantTheme,
  useSetActiveTenantTheme,
  useTenantTheme,
  useTenantThemes,
  useThemeTemplates,
  type ThemeTemplate,
} from '@/queries/tenantThemes';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { MoodBoard } from '@/ui/MoodBoard';
import { PageHeader, Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice, pill } from '@/ui/tone';
import { When } from '@/ui/When';
import { cn } from '@/utils/cn';

import { failingPairs, isCssValue, recolour, themeProperties, type ThemeDocument, type ThemeMode } from './themeDocument';

/**
 * `/themes` — the organisation's themes (2026-10-04).
 *
 * Five templates from the platform to start from; the organisation's own
 * copies, edited here — colours in both modes, the font families, the type
 * scale — with the mood board drawn from the draft and every text pair
 * measured as it changes; and one chosen as what its members' screens wear.
 *
 * A template is never edited in place: "Edit a copy" opens it as a draft,
 * and saving writes the organisation's own theme. Another organisation's
 * screens do not move. Behind `skin.manage`, and nothing else — the operator
 * chose not to tie it to the offer (2026-10-04).
 */
const NAME = /^[a-z0-9][a-z0-9-]{0,62}$/;
const MODES: readonly ThemeMode[] = ['light', 'dark'];

function modeLabel(mode: ThemeMode): string {
  return mode === 'light' ? t('Light') : t('Dark');
}

interface Draft {
  readonly name: string;
  readonly document: ThemeDocument;
}

export function ThemesScreen() {
  const templates = useThemeTemplates();
  const mine = useTenantThemes();
  const [draft, setDraft] = useState<Draft | null>(null);
  // An organisation's own theme is fetched when it is opened, and seeds the
  // draft once it arrives — during render, as React resets state on a prop.
  const [opening, setOpening] = useState<string | null>(null);
  const opened = useTenantTheme(opening);

  if (opening !== null && opened.data !== undefined && opened.data.name === opening) {
    setOpening(null);
    setDraft({ name: opened.data.name, document: opened.data.document });
  }

  const active = mine.data?.find((theme) => theme.active) ?? null;

  return (
    <div className="max-w-6xl space-y-10">
      <PageHeader
        title={t('Themes')}
        description={t(
          'How your organisation’s screens look: colours in light and dark, fonts and type scale. Start from one of the platform’s templates, edit a copy, save it, and choose which one your members see.',
        )}
      />

      <Section title={t('Templates')} description={t('The platform’s five starting points. Editing one makes a copy in your organisation; the template itself never changes.')}>
        {templates.isPending ? (
          <SkeletonRows rows={3} />
        ) : templates.error !== null ? (
          <ErrorSurface error={templates.error} onRetry={() => void templates.refetch()} />
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {templates.data.map((template) => (
              <TemplateCard key={template.name} template={template} onEdit={() => setDraft({ name: template.name, document: template.document })} />
            ))}
          </ul>
        )}
      </Section>

      <Section
        title={t('Your themes')}
        description={
          active === null
            ? t('Your members see the platform’s own design.')
            : t('Your members see {name}.', { name: active.name })
        }
      >
        <YourThemes
          onEdit={(name) => {
            setDraft(null);
            setOpening(name);
          }}
        />
        {opened.error !== null && <ErrorSurface error={opened.error} />}
      </Section>

      {draft !== null && <Editor key={draft.name} initial={draft} onClose={() => setDraft(null)} />}
    </div>
  );
}

/** Two rows of colour, light over dark, straight from the document's values. */
function Swatches({ document }: { document: ThemeDocument }) {
  const tokens = document.colors.flatMap((group) => group.tokens);

  return (
    <div className="overflow-hidden rounded-control border border-line" aria-hidden="true">
      {MODES.map((mode) => (
        <div key={mode} className="flex h-5">
          {tokens.map((token) => (
            <span key={token.name} className="flex-1" style={{ backgroundColor: token[mode] }} />
          ))}
        </div>
      ))}
    </div>
  );
}

function TemplateCard({ template, onEdit }: { template: ThemeTemplate; onEdit: () => void }) {
  const document = template.document;
  const sans = document.fonts.find((font) => font.role === 'sans');

  return (
    <li className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-raise" data-testid={`template-${template.name}`}>
      <Swatches document={document} />
      <div className="space-y-1">
        <h3 className="text-lg font-semibold">{document.label ?? template.name}</h3>
        <p className="font-mono text-2xs text-muted">{template.name}</p>
        {document.description !== undefined && <p className="text-sm text-muted">{document.description}</p>}
        {sans !== undefined && <p className="text-xs text-muted">{t('Typeface: {family}', { family: sans.family })}</p>}
      </div>
      <div className="mt-auto">
        <Button type="button" variant="secondary" onClick={onEdit}>
          {t('Edit a copy')}
        </Button>
      </div>
    </li>
  );
}

function YourThemes({ onEdit }: { onEdit: (name: string) => void }) {
  const mine = useTenantThemes();
  const use = useSetActiveTenantTheme();

  if (mine.isPending) {
    return <SkeletonRows rows={2} />;
  }

  if (mine.error !== null) {
    return <ErrorSurface error={mine.error} onRetry={() => void mine.refetch()} />;
  }

  if (mine.data.length === 0) {
    return <p className="text-sm text-muted">{t('No theme saved yet. Edit a copy of a template to make one.')}</p>;
  }

  const active = mine.data.find((theme) => theme.active);

  return (
    <div className="space-y-3">
      <ul className="divide-y divide-line rounded-card border border-line bg-surface" data-testid="my-themes">
        {mine.data.map((theme) => (
          <li key={theme.name} className="flex flex-wrap items-center gap-3 px-4 py-3" data-theme-name={theme.name}>
            <span className="font-mono text-sm font-semibold">{theme.name}</span>
            {theme.active && <span className={pill('success')}>{t('In use')}</span>}
            <span className="text-xs text-muted">
              <When at={theme.updated_at} />
            </span>
            <div className="ml-auto flex flex-wrap gap-2">
              <Button type="button" variant="secondary" onClick={() => onEdit(theme.name)}>
                {t('Edit')}
              </Button>
              {!theme.active && (
                <Button type="button" pending={use.isPending && use.variables === theme.name} onClick={() => use.mutate(theme.name)}>
                  {t('Use for my organisation')}
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>

      {active !== undefined && (
        <Button type="button" variant="secondary" pending={use.isPending && use.variables === null} onClick={() => use.mutate(null)}>
          {t('Go back to the platform’s design')}
        </Button>
      )}
      {use.error !== null && <ErrorSurface error={use.error} />}
    </div>
  );
}

function Editor({ initial, onClose }: { initial: Draft; onClose: () => void }) {
  const [name, setName] = useState(initial.name);
  const [document, setDocument] = useState(initial.document);
  const save = useSaveTenantTheme();
  const use = useSetActiveTenantTheme();

  const validName = NAME.test(name);
  const invalidValues = document.colors.some((group) => group.tokens.some((token) => !isCssValue(token.light) || !isCssValue(token.dark)));
  const failing = failingPairs(document);
  const stripe = document.colors.flatMap((group) => group.tokens.map((token) => token.name));

  // Every font stack the templates and this draft carry: the families the
  // platform serves. A family typed by hand would fall back silently, because
  // the page may load fonts from its own origin only.
  const templates = useThemeTemplates();
  const stacks = (role: string) => {
    const all = [...(templates.data ?? []).map((template) => template.document), document]
      .flatMap((doc) => doc.fonts)
      .filter((font) => font.role === role);

    return [...new Map(all.map((font) => [font.stack, font])).values()];
  };

  const submit = (andUse: boolean) =>
    save.mutate(
      { name, document },
      {
        onSuccess: (theme) => {
          if (andUse) {
            use.mutate(theme.name);
          }
        },
      },
    );

  return (
    <Section
      title={t('Editing {name}', { name: initial.name })}
      description={t('Changes are a draft until you save. Saving the theme your members see changes their screens at their next page load.')}
      data-testid="theme-editor"
    >
      <div className="space-y-6">
        <div className="grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2">
          <Field
            id="theme-name"
            label={t('Name')}
            hint={t('Lower-case letters, digits and hyphens. Saving under a template’s name saves your own copy.')}
            error={validName ? undefined : t('Use lower-case letters, digits and hyphens.')}
          >
            <input id="theme-name" className={cn(inputClass(!validName), 'font-mono')} value={name} onChange={(event) => setName(event.target.value)} spellCheck={false} autoComplete="off" />
          </Field>
          <Field id="theme-label" label={t('Label')} hint={t('What the theme is called, for people.')}>
            <input
              id="theme-label"
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
              testId={`theme-preview-${mode}`}
            />
          ))}
        </div>

        {failing.length === 0 ? (
          <p className={notice('success')} data-testid="theme-contrast" role="status">
            {t('Every text pair clears WCAG AA, in both modes.')}
          </p>
        ) : (
          <div className={notice('warning')} data-testid="theme-contrast" role="status">
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
          <Button type="button" data-testid="save-tenant-theme" pending={save.isPending} disabled={!validName || invalidValues} onClick={() => submit(false)}>
            {t('Save')}
          </Button>
          <Button type="button" variant="secondary" pending={save.isPending || use.isPending} disabled={!validName || invalidValues} onClick={() => submit(true)}>
            {t('Save and use for my organisation')}
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('Close')}
          </Button>
          {save.isSuccess && (
            <span role="status" className="text-xs text-success" data-testid="theme-saved">
              {t('Saved.')}
            </span>
          )}
        </div>
        {save.error !== null && <ErrorSurface error={save.error} />}
        {use.error !== null && <ErrorSurface error={use.error} />}
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
