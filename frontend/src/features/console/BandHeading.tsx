import { BandFieldEditor, type BandField } from '@/features/showcase/blocks/editors';
import { BAND_META, layoutsOf, PAGE_MENUS, type BandKind } from '@/features/showcase/blocks/meta';
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

/**
 * The fields one band's heading is edited in: the three sentences, and its
 * layout where the band has more than one (2026-11-20).
 *
 * The layout is a `choices` field, so the editor draws one select and no
 * language button — it is a code, and the server refuses it in a locale.
 * Its *None* is the band's usual layout, which is what an absent value
 * means everywhere this is read.
 */
/**
 * The fields one band's heading is edited in: the three sentences, and its
 * layout where the band has more than one (2026-11-20).
 *
 * The layout is a `choices` field, so the editor draws one select and no
 * language button — it is a code, and the server refuses it in a locale.
 * Its *None* is the band's usual layout, which is what an absent value
 * means everywhere this is read.
 */
/**
 * The other columns of a comparison table (2026-11-23): a sentence, so it
 * is translated like a title, carried by that band's heading alone.
 */
export const COLUMNS_FIELD: BandField = {
  name: 'columns',
  label: 'The other columns',
  required: false,
  hint: 'Their names, separated by ; — for example: An architect; A spreadsheet; Nothing. Three at most.',
};

/** The sentences above a band, and the columns where the band has them. */
export function headingSentencesOf(block: BandKind): readonly BandField[] {
  return block === 'VERSUS' ? [...HEADING_FIELDS, COLUMNS_FIELD] : HEADING_FIELDS;
}

export function headingFieldsOf(block: BandKind): readonly BandField[] {
  const layouts = layoutsOf(block);

  return [
    ...headingSentencesOf(block),
    ...(layouts.length === 0
      ? []
      : [
          {
            name: 'layout',
            label: 'Layout',
            required: false,
            choices: layouts.slice(1),
            hint: 'How this band is laid out. None is the usual one.',
          },
        ]),
    // The page's menu is the headline's (2026-11-22): the bar sits under it,
    // and a page has one of each.
    ...(block === 'HEADLINE'
      ? [
          {
            name: 'menu',
            label: 'Menu under the headline',
            required: false,
            choices: PAGE_MENUS.slice(1),
            hint: 'The bar naming the bands of the page. None is the usual one: it is drawn.',
          },
        ]
      : []),
  ];
}

/**
 * The fields one band's heading is edited in: the three sentences, and its
 * layout where the band has more than one (2026-11-20).
 *
 * The layout is a `choices` field, so the editor draws one select and no
 * language button — it is a code, and the server refuses it in a locale.
 * Its *None* is the band's usual layout, which is what an absent value
 * means everywhere this is read.
 */
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

      {headingFieldsOf(block).map((field) => (
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
