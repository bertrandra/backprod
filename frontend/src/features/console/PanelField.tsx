import { useId } from 'react';

import type { BandField } from '@/features/showcase/blocks/editors';
import { inputClass } from '@/ui/Field';
import type { Translated } from '@/ui/TranslatedField';
import { cn } from '@/utils/cn';
import { t, type LocaleCode } from '@/i18n';

/**
 * One field of the page editor's panel (2026-11-23).
 *
 * **One language at a time, chosen once.** The console's field carries its
 * own language button and a line of five codes under it, which is right on
 * a form somebody reads top to bottom and was most of what made the
 * editor's panel unreadable: every field said "EN ▾" and "EN FR ES DE IT".
 * Here the language is the editor's — the one the page is shown in — so a
 * field is a label and a box. In another language the English stands in
 * the empty box, which is also what a visitor would read there.
 *
 * **The hint appears when the field is used**, not before. It was a second
 * line under every label, read once and then in the way.
 *
 * A code (an icon, a shape) has no translation and always edits the
 * English, exactly as the server stores it.
 */
export function PanelField({
  id,
  field,
  value,
  locale,
  onChange,
}: {
  id: string;
  field: BandField;
  value: Translated;
  locale: LocaleCode;
  onChange: (next: Translated) => void;
}) {
  const hint = useId();
  const label = t(field.label);

  if (field.choices !== undefined) {
    return (
      <div className="space-y-1.5">
        <label htmlFor={id} className="block text-sm font-medium">
          {label}
        </label>
        <select
          id={id}
          data-testid={`choice-${id}`}
          className={inputClass()}
          value={value.en}
          onChange={(event) => onChange({ ...value, en: event.target.value })}
        >
          <option value="">{t('None')}</option>
          {field.choices.map((choice) => (
            <option key={choice} value={choice}>
              {choice}
            </option>
          ))}
        </select>
      </div>
    );
  }

  if (field.code !== undefined) {
    return (
      <div className="space-y-1.5">
        <label htmlFor={id} className="block text-sm font-medium">
          {label}
        </label>
        <input
          id={id}
          type={field.code === 'number' ? 'number' : field.code}
          min={field.code === 'number' ? 0 : undefined}
          max={field.code === 'number' ? 100 : undefined}
          aria-describedby={field.hint === undefined ? undefined : hint}
          className={cn(inputClass(), 'peer')}
          value={value.en}
          onChange={(event) => onChange({ ...value, en: event.target.value })}
        />
        {field.hint !== undefined && (
          <p id={hint} className="hidden text-xs text-muted peer-focus:block">
            {t(field.hint)}
          </p>
        )}
      </div>
    );
  }

  const english = locale === 'en';
  const shown = english ? value.en : (value[locale] ?? '');
  const missing = english && field.required && value.en.trim() === '';
  const write = (text: string) => onChange(english ? { ...value, en: text } : { ...value, [locale]: text });
  const shared = {
    id,
    value: shown,
    // The English, where this language says nothing yet: what a visitor
    // reading in it would see, and what to translate.
    placeholder: english ? undefined : value.en,
    'aria-describedby': field.hint === undefined ? undefined : hint,
    'aria-invalid': missing || undefined,
    className: cn(inputClass(), 'peer'),
  };

  return (
    <div className="space-y-1.5">
      <div className="flex items-baseline gap-2">
        <label htmlFor={id} className="text-sm font-medium">
          {label}
        </label>
        {missing && <span className="text-xs text-danger">{t('required')}</span>}
      </div>

      {field.long === true ? (
        <textarea {...shared} rows={3} onChange={(event) => write(event.target.value)} />
      ) : (
        <input {...shared} onChange={(event) => write(event.target.value)} />
      )}

      {field.hint !== undefined && (
        <p id={hint} className="hidden text-xs text-muted peer-focus:block">
          {t(field.hint)}
        </p>
      )}
    </div>
  );
}
