import { useState } from 'react';

import { currentLocale, LOCALE_NAMES, LOCALES, t, type LocaleCode } from '@/i18n';
import { inputClass, touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

/** What one thing is called, by language. English lives apart, as the key. */
export type Translated = {
  readonly en: string;
  readonly fr?: string | null;
  readonly es?: string | null;
  readonly de?: string | null;
  readonly it?: string | null;
};

const OTHERS: readonly LocaleCode[] = LOCALES.filter((code) => code !== 'en');

/**
 * One field, five languages, and a button at its end (2026-09-24).
 *
 * The operator writes what they sell; the platform writes what the
 * application says. This is the first kind: a feature's name, an offer's,
 * a showcase headline later. The application's own sentences stay in
 * `src/i18n/catalogues/` and have nothing to do with this
 * (`docs/translatable-fields-spec.md` §1.5).
 *
 * **One control rather than a workbench.** Five tabs, or five stacked
 * inputs, turn a catalogue screen into a translation tool for a job most
 * people do in one language and come back to later. The button names the
 * language being edited and opens the others; the dots say which ones are
 * filled, so a gap is visible without opening anything — which is what
 * makes this honest rather than decorative.
 *
 * The mails keep their four tabs on purpose: a mail is a subject *and* a
 * body *and* a link, edited and previewed together, and one tab per
 * language is the shape that fits there.
 *
 * English cannot be emptied — it is the key and the fallback. The others
 * can, and an empty one falls back to it rather than showing a blank.
 */
export function TranslatedField({
  id,
  value,
  onChange,
  disabled = false,
  placeholder,
}: {
  id: string;
  value: Translated;
  onChange: (next: Translated) => void;
  disabled?: boolean;
  placeholder?: string | undefined;
}) {
  // Opens on the language this person reads in, which is the one they are
  // most likely to be adding — and English when that is what they read.
  const [editing, setEditing] = useState<LocaleCode>(currentLocale());
  const [open, setOpen] = useState(false);

  const shown = editing === 'en' ? value.en : (value[editing] ?? '');

  return (
    <div className="space-y-1">
      <div className="relative">
        <input
          id={id}
          className={cn(inputClass(), 'pr-24')}
          value={shown}
          disabled={disabled}
          placeholder={editing === 'en' ? placeholder : value.en}
          data-testid={`translated-${id}`}
          data-editing={editing}
          onChange={(event) =>
            onChange(
              editing === 'en'
                ? { ...value, en: event.target.value }
                : { ...value, [editing]: event.target.value },
            )
          }
        />

        <button
          type="button"
          data-testid={`language-of-${id}`}
          aria-expanded={open}
          aria-label={t("Language of this field")}
          disabled={disabled}
          onClick={() => setOpen((was) => !was)}
          className={cn(
            touchTargetClass,
            'absolute inset-y-0 right-0 flex items-center gap-1 rounded-control px-3 text-xs font-semibold text-muted',
            'hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2',
          )}
        >
          {editing.toUpperCase()}
          <span aria-hidden="true">▾</span>
        </button>
      </div>

      {open && (
        <div role="group" aria-label={t("Language of this field")} className="flex flex-wrap gap-1">
          {LOCALES.map((code) => (
            <button
              key={code}
              type="button"
              data-testid={`language-${code}-of-${id}`}
              aria-pressed={code === editing}
              onClick={() => {
                setEditing(code);
                setOpen(false);
              }}
              className={cn(
                'rounded-control border px-2 py-1 text-xs',
                code === editing ? 'border-accent text-ink' : 'border-line text-muted hover:text-ink',
              )}
            >
              {LOCALE_NAMES[code]}
            </button>
          ))}
        </div>
      )}

      {/* Which languages say something, without opening anything. A gap
          here is the whole reason somebody comes back to this screen. */}
      <p className="flex items-center gap-1 text-xs text-subtle" data-testid={`written-in-${id}`}>
        <span className="sr-only">{t("Written in:")}</span>
        {LOCALES.map((code) => {
          const written = code === 'en' ? value.en.trim() !== '' : (value[code] ?? '').trim() !== '';

          return (
            <span
              key={code}
              title={LOCALE_NAMES[code]}
              data-locale={code}
              data-written={written ? 'true' : 'false'}
              className={written ? 'text-ink' : 'text-subtle/50'}
            >
              {code.toUpperCase()}
            </span>
          );
        })}
      </p>
    </div>
  );
}

/** The shape the API takes: the English apart, the rest as a map. */
export function asTranslations(value: Translated): Record<string, { name: string | null }> {
  const translations: Record<string, { name: string | null }> = {};

  for (const code of OTHERS) {
    const written = (value[code] ?? '').trim();

    // A language the operator emptied is sent as an absent one: the server
    // replaces the set, so leaving it out is how it is removed.
    if (written !== '') {
      translations[code] = { name: written };
    }
  }

  return translations;
}
