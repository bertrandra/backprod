import { BandFieldEditor, type BandField } from '@/features/showcase/blocks/editors';
import { BAND_META, type BandKind } from '@/features/showcase/blocks/meta';
import type { Translated } from '@/ui/TranslatedField';
import { t } from '@/i18n';

/**
 * A band's own heading — its eyebrow, its title, the sentence under it
 * (2026-09-28).
 *
 * **One form per band, not per row.** Three steps are three rows and one
 * heading: the section is called "How it works" once, and putting that on a
 * row would mean renaming the section by editing step one.
 *
 * **Every field is optional, and empty is a real answer**: the band then
 * reads the words its component was written with. That is why the fields
 * carry no `required` mark and why the hint says what leaving one empty
 * does — an operator who wants the default has to be able to reach it, and
 * deleting a title is how.
 *
 * `PRICING` gets one of these and no row editor at all, which is the whole
 * reason headings are their own thing: it has something to say about its
 * prices and no row anybody could type one into (§9).
 */
export const HEADING_FIELDS: readonly BandField[] = [
  {
    name: 'eyebrow',
    label: 'Eyebrow',
    required: false,
    hint: 'The short line above the title. Empty: none is drawn.',
  },
  {
    name: 'title',
    label: 'Title',
    required: false,
    hint: 'Empty: the words this band was written with.',
  },
  {
    name: 'lede',
    label: 'Under the title',
    required: false,
    long: true,
    hint: 'One sentence, before the band itself starts.',
  },
];

export function BandHeadingEditor({
  block,
  fields,
  onChange,
}: {
  block: BandKind;
  fields: Record<string, Translated>;
  onChange: (field: string, next: Translated) => void;
}) {
  return (
    <section
      data-heading={block}
      className="space-y-3 rounded-card border border-line bg-surface p-5 shadow-raise"
    >
      <div className="flex flex-wrap items-baseline gap-3">
        <h3 className="font-medium">{t(BAND_META[block].nav)}</h3>
        {block === 'PRICING' && (
          // Said here because it is the one band with no rows below it, and
          // somebody will look for them.
          <span className="text-xs text-subtle" data-testid="pricing-reads-the-catalogue">
            {t('the prices come from the catalogue')}
          </span>
        )}
      </div>

      {HEADING_FIELDS.map((field) => (
        <BandFieldEditor
          key={field.name}
          id={`heading-${block}-${field.name}`}
          field={field}
          value={fields[field.name] ?? { en: '' }}
          onChange={(next) => onChange(field.name, next)}
        />
      ))}
    </section>
  );
}
