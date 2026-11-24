import { TranslatedField, type Translated } from '@/ui/TranslatedField';
import { Field, inputClass } from '@/ui/Field';
import { t } from '@/i18n';

import { DEMO_RATIO_NAMES } from './DemoBand';
import { AUTHORED_BANDS, BAND_META, PROBLEM_ICONS, type AuthoredBandKind } from './meta';

/** The icon codes, for the one field that offers a list rather than a box. */
const PROBLEM_ICON_NAMES = Object.keys(PROBLEM_ICONS);

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
  /**
   * A closed set of codes rather than a sentence.
   *
   * The field is then **not translated** — the server refuses it in a
   * locale — so the editor shows one control and not five. An icon has no
   * French.
   */
  readonly choices?: readonly string[];
  /** A sentence rather than a phrase: the control grows. */
  readonly long?: boolean;
  /** What this field is for, where the label alone does not say it. */
  readonly hint?: string;
  /**
   * A code typed rather than chosen (2026-11-23): an address, a day or a
   * share of a picture. English only, like a choice, and drawn as the input
   * the browser has for it.
   */
  readonly code?: 'url' | 'date' | 'number';
  /** What a new row starts with, where an empty field would draw nothing. */
  readonly initial?: string;
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
    { name: 'reassurance', label: 'Under the buttons', required: false, hint: 'What it does not cost, what it does not install \u2014 for somebody hesitating over the button above it.' },
    PICTURE_DESCRIPTION,
  ],
  PROBLEM: [
    { name: 'title', label: 'What it costs them', required: true },
    { name: 'body', label: 'Why', required: false, long: true },
    { name: 'icon', label: 'Icon', required: false, choices: PROBLEM_ICON_NAMES, hint: 'Drawn by the page, so it has no translation. None is a fine answer.' },
  ],
  QUOTE: [
    { name: 'quote', label: 'What they said', required: true, long: true },
    { name: 'author', label: 'Who said it', required: false, hint: 'Their name and what they do. Left empty, nothing stands in for it.' },
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
  // The address is offered beside the picture and not instead of it: the
  // server refuses a row carrying both, so an operator who fills the two
  // is told which to drop rather than having one silently win.
  DEMO: [
    { name: 'caption', label: 'Caption', required: true, hint: 'Read whatever the browser decides about the frame, so it says what this shows.' },
    {
      name: 'embed_url',
      label: 'Address to embed',
      required: false,
      hint: 'An https address. Write {width} and {height} where its size goes — the page puts in the pixels it drew at. Leave empty to show a picture instead.',
    },
    {
      name: 'ratio',
      label: 'Shape',
      required: false,
      choices: DEMO_RATIO_NAMES,
      hint: 'The box reserved before anything loads. 4:3 for most drawings, 16:9 for video.',
    },
    PICTURE_DESCRIPTION,
  ],
  QUESTION: [
    { name: 'question', label: 'Question', required: true },
    { name: 'answer', label: 'Answer', required: true, long: true },
  ],
  // No picture description: the name is what a screen reader says for the
  // logo, and the server refuses a second one.
  TRUST: [
    { name: 'name', label: 'Who', required: true, hint: 'A customer, a label, a certification. Shown as text where no logo is chosen, and read aloud for the logo where one is.' },
  ],
  STATS: [
    { name: 'value', label: 'The figure', required: true, hint: 'As it is to be read, unit included: 3 min, 1 200, −40 %.' },
    { name: 'label', label: 'What it counts', required: true },
    { name: 'detail', label: 'Where it comes from', required: false, hint: 'One line: the source, or what it is compared with.' },
  ],
  FEATURE: [
    { name: 'title', label: 'What it includes', required: true },
    { name: 'body', label: 'What that gives them', required: false, long: true },
    { name: 'icon', label: 'Icon', required: false, choices: PROBLEM_ICON_NAMES, hint: 'Drawn by the page, so it has no translation. None is a fine answer.' },
  ],
  TOUR: [
    { name: 'title', label: 'What to see', required: true, hint: 'The name on the tab. Short: it sits in a list of them.' },
    { name: 'body', label: 'What the picture shows', required: false, long: true },
    PICTURE_DESCRIPTION,
  ],
  // Two of these make the comparison: the first is before, the second
  // after. Each is an address or a picture, as on a demo row.
  COMPARE: [
    { name: 'label', label: 'This side is called', required: true, hint: 'Before, After — or the two things being compared. A page has two of these, read in their order.' },
    { name: 'caption', label: 'One line about it', required: false },
    {
      name: 'embed_url',
      label: 'Address to embed',
      required: false,
      hint: 'An https address, to let the reader move around in it. Write {width} and {height} where its size goes. Leave empty to show a picture instead: two pictures are compared with a slider.',
    },
    {
      name: 'ratio',
      label: 'Shape',
      required: false,
      choices: DEMO_RATIO_NAMES,
      hint: 'The box reserved before anything loads. The first side decides for both.',
    },
    PICTURE_DESCRIPTION,
  ],
  CTA: [
    { name: 'title', label: 'The last sentence', required: true, hint: 'Above the button the headline already carries. A page has one of these.' },
    { name: 'body', label: 'Under it', required: false, long: true },
  ],
  CONTACT: [
    { name: 'intro', label: 'Above the form', required: false, long: true, hint: 'What to write about, and how soon you answer.' },
    { name: 'consent', label: 'Beside the box they tick', required: true, initial: 'I agree to be contacted about my message.', hint: 'Kept with each message, as the visitor read it.' },
    { name: 'thanks', label: 'Once it is sent', required: false, hint: 'Empty: a short thank-you.' },
  ],
  ANNOUNCE: [
    { name: 'text', label: 'The news', required: true, hint: 'One line, above the whole page.' },
    { name: 'link_label', label: 'What the link says', required: false },
    { name: 'link_url', label: 'Where it goes', required: false, code: 'url', hint: 'An https address, or #prices to stay on the page.' },
    { name: 'until', label: 'News until', required: false, code: 'date', hint: 'Past this day it is taken off the page. Empty: until you remove it.' },
  ],
  VIDEO: [
    { name: 'video_url', label: 'The video', required: true, code: 'url', hint: 'A YouTube or Vimeo address, as copied from the browser.' },
    { name: 'caption', label: 'What it shows', required: true },
    PICTURE_DESCRIPTION,
  ],
  VERSUS: [
    { name: 'criterion', label: 'What is compared', required: true },
    { name: 'note', label: 'A line under it', required: false },
    { name: 'ours', label: 'This product', required: false, choices: ['yes', 'partly', 'no'], initial: 'yes' },
    { name: 'first', label: 'First other way', required: false, choices: ['yes', 'partly', 'no'] },
    { name: 'second', label: 'Second other way', required: false, choices: ['yes', 'partly', 'no'] },
    { name: 'third', label: 'Third other way', required: false, choices: ['yes', 'partly', 'no'] },
  ],
  REASSURE: [
    { name: 'title', label: 'What you promise', required: true, hint: 'A few words: Secure payment, Cancel whenever you like, An answer within a day.' },
    { name: 'detail', label: 'What it rests on', required: false, hint: 'One short line under it. Leave it empty if the promise says it all.' },
    { name: 'icon', label: 'Icon', required: false, choices: PROBLEM_ICON_NAMES, initial: 'check', hint: 'Drawn by the page, so it has no translation.' },
  ],
  REVIEW: [
    // The author first: it is what the row is called in the list.
    { name: 'author', label: 'Who wrote it', required: true },
    { name: 'rating', label: 'Stars', required: true, choices: ['5', '4', '3', '2', '1'], initial: '5', hint: 'The mark this customer gave, as they gave it.' },
    { name: 'body', label: 'What they wrote', required: true, long: true, hint: 'Their words, from a review you really received.' },
    { name: 'role', label: 'Who they are', required: false, hint: 'What they do, or where: Architect, Lyon.' },
    { name: 'date', label: 'When', required: false, code: 'date' },
  ],
  HOTSPOT: [
    { name: 'title', label: 'What is here', required: true },
    { name: 'body', label: 'What it does', required: false, long: true },
    { name: 'x', label: 'Across', required: true, code: 'number', initial: '50', hint: 'From 0 (left edge) to 100 (right edge). Or click the picture.' },
    { name: 'y', label: 'Down', required: true, code: 'number', initial: '50', hint: 'From 0 (top) to 100 (bottom). Or click the picture.' },
    PICTURE_DESCRIPTION,
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
  // A code, not a sentence: one control, no language button, and the
  // options are the ones the page can draw.
  if (field.code !== undefined) {
    return (
      <Field id={id} label={t(field.label)} hint={field.hint === undefined ? undefined : t(field.hint)}>
        <input
          id={id}
          type={field.code === 'number' ? 'number' : field.code}
          min={field.code === 'number' ? 0 : undefined}
          max={field.code === 'number' ? 100 : undefined}
          className={inputClass()}
          value={value.en}
          onChange={(event) => onChange({ ...value, en: event.target.value })}
        />
      </Field>
    );
  }

  if (field.choices !== undefined) {
    return (
      <Field id={id} label={t(field.label)} hint={field.hint === undefined ? undefined : t(field.hint)}>
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
      </Field>
    );
  }

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
