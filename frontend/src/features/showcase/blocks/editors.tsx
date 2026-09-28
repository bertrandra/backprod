import { TranslatedField, type Translated } from '@/ui/TranslatedField';
import { Field } from '@/ui/Field';
import { t } from '@/i18n';

import { AUTHORED_BANDS, BAND_META, type AuthoredBandKind } from './meta';

/**
 * The fields each band is written in, and their order on the form.
 *
 * The **fifth edit** the specification's "four edits" does not count
 * (§6): a band's shape is known in three places — the migration's enum, the
 * server's validator and this table — and there is no honest way to make it
 * two. What this table buys is that the *form* is not a fourth: the console
 * screen renders whatever is listed here, so adding a band adds a row and
 * no JSX.
 *
 * `required` is required **in English only**. A translation says what
 * somebody has got round to writing, and demanding a full set in every
 * language is what §11.2 refused.
 */
export interface BandField {
  readonly name: string;
  readonly label: string;
  readonly required: boolean;
  /** A sentence rather than a phrase: the control grows. */
  readonly long?: boolean;
  /** What this field is for, where the label alone does not say it. */
  readonly hint?: string;
}

/**
 * Describing the picture, on every band that carries one.
 *
 * **It was accepted by the server and offered by nothing** until
 * 2026-09-28: `ShowcaseBlocks` has listed `alt` among a headline's optional
 * fields since the first version, `pictureOf` reads it, and
 * `ShowcaseImageFrame`'s own comment says "the operator writes the `alt`,
 * and the console offers the field" — which it did not. So every picture
 * ever put on a showcase went out decorative, the hero included, and the
 * one place a reader on a screen reader most needs a sentence had none.
 *
 * Worse than absent, it was **destructive**: `toInput` builds a band's
 * content from this table alone, so an `alt` that reached the database by
 * any other route was dropped by the next save from this screen.
 *
 * Empty stays meaningful and the hint says so. A proof band's caption sits
 * beside its picture already, and an `alt` repeating it makes a screen
 * reader say the same sentence twice.
 */
const PICTURE_DESCRIPTION: BandField = {
  name: 'alt',
  label: 'What the picture shows',
  required: false,
  hint: 'For readers who cannot see it. Leave it empty if the picture only decorates.',
};

export const BAND_FIELDS: Record<AuthoredBandKind, readonly BandField[]> = {
  HEADLINE: [
    { name: 'headline', label: 'Headline', required: true },
    { name: 'subline', label: 'Subline', required: false, long: true },
    PICTURE_DESCRIPTION,
  ],
  STEPS: [
    { name: 'title', label: 'Step', required: true },
    { name: 'body', label: 'What happens', required: false, long: true },
    PICTURE_DESCRIPTION,
  ],
  USE_CASE: [
    { name: 'who', label: 'Who', required: true },
    { name: 'before', label: 'Before', required: false, long: true },
    { name: 'after', label: 'Now', required: false, long: true },
    PICTURE_DESCRIPTION,
  ],
  PROOF: [{ name: 'caption', label: 'Caption', required: true, long: true }, PICTURE_DESCRIPTION],
  QUESTION: [
    { name: 'question', label: 'Question', required: true },
    { name: 'answer', label: 'Answer', required: true, long: true },
  ],
};

/** What the console offers to add, in the order the page reads. */
export function authoredBandsInOrder(): readonly AuthoredBandKind[] {
  return [...AUTHORED_BANDS].sort((a, b) => BAND_META[a].order - BAND_META[b].order);
}

/**
 * One field of one band, in five languages.
 *
 * `TranslatedField` unchanged: the language button at the end of the
 * control, the dots saying which languages say something. Nothing here
 * invents a second translation interface, which is the rule the catalogue's
 * fields already follow.
 */
export function BandFieldEditor({
  id,
  field,
  value,
  onChange,
}: {
  id: string;
  field: BandField;
  value: Translated;
  onChange: (next: Translated) => void;
}) {
  return (
    <Field
      id={id}
      label={t(field.label)}
      hint={
        field.hint !== undefined
          ? t(field.hint)
          : field.required
            ? t("Required, in English")
            : undefined
      }
    >
      {/* Opens on English, whatever language the operator reads in. The
          English is what the page is published from, so an editor that
          opened on French would let somebody write the whole story, press
          Save, and be refused for a field they were never shown. The
          language button is right there for the other four. */}
      <TranslatedField id={id} value={value} onChange={onChange} openOn="en" />
    </Field>
  );
}
