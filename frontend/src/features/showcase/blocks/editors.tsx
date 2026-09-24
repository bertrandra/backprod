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
}

export const BAND_FIELDS: Record<AuthoredBandKind, readonly BandField[]> = {
  HEADLINE: [
    { name: 'headline', label: 'Headline', required: true },
    { name: 'subline', label: 'Subline', required: false, long: true },
  ],
  STEPS: [
    { name: 'title', label: 'Step', required: true },
    { name: 'body', label: 'What happens', required: false, long: true },
  ],
  USE_CASE: [
    { name: 'who', label: 'Who', required: true },
    { name: 'before', label: 'Before', required: false, long: true },
    { name: 'after', label: 'Now', required: false, long: true },
  ],
  PROOF: [{ name: 'caption', label: 'Caption', required: true, long: true }],
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
    <Field id={id} label={t(field.label)} hint={field.required ? t("Required, in English") : undefined}>
      {/* Opens on English, whatever language the operator reads in. The
          English is what the page is published from, so an editor that
          opened on French would let somebody write the whole story, press
          Save, and be refused for a field they were never shown. The
          language button is right there for the other four. */}
      <TranslatedField id={id} value={value} onChange={onChange} openOn="en" />
    </Field>
  );
}
