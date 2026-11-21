import { BAND_FIELDS } from '@/features/showcase/blocks/editors';
import type { AuthoredBandKind, BandKind } from '@/features/showcase/blocks/meta';
import { LOCALES, type LocaleCode } from '@/i18n';
import type { Translated } from '@/ui/TranslatedField';

import { HEADING_FIELDS } from './BandHeading';
import { EMPTY, headingsToInput, toInput, type DraftBlock, type HeadingDraft } from './storyDraft';

/**
 * What the page editor asks of a draft before it is saved (2026-11-21).
 *
 * **Pure, and beside the draft rather than inside a component**, for the
 * reason `storyDraft` is: the editor shows these answers in three places —
 * the bar, the page panel, the review before a save — and three components
 * each working them out would be three opinions about what "changed" means.
 *
 * None of it is an authority. The server refuses what it refuses; this
 * says so earlier, where the sentence can still be rewritten.
 */

/** The whole of what is being edited: rows, order and headings together. */
export interface StoryState {
  readonly draft: readonly DraftBlock[];
  readonly order: readonly BandKind[];
  readonly headings: HeadingDraft;
}

/**
 * The bands a reader would meet, in the order they would meet them.
 *
 * The headline and the prices are always drawn — the first falls back to
 * the product's name, the second reads the catalogue — and every other band
 * is drawn once it has a row. The page's own rule (`speaks`), said here
 * about a draft.
 */
export function bandsOnThePage(state: StoryState): readonly BandKind[] {
  return state.order.filter(
    (kind) => kind === 'HEADLINE' || kind === 'PRICING' || state.draft.some((block) => block.block === kind),
  );
}

export type ChangeKind = 'added' | 'removed' | 'rewritten' | 'layout';

export interface StoryChange {
  /** The band it is about, or null for the order of the page as a whole. */
  readonly band: BandKind | null;
  readonly what: ChangeKind | 'order';
}

function rowsOf(state: StoryState, kind: BandKind): string {
  return JSON.stringify(
    state.draft
      .filter((block) => block.block === kind)
      .map((block, index) => toInput(block, index)),
  );
}

function headingOf(state: StoryState, kind: BandKind): { words: string; layout: string } {
  const heading = headingsToInput({ [kind]: state.headings[kind] })[kind];
  const content = { ...((heading?.content ?? {}) as Record<string, string>) };
  const layout = content.layout ?? '';

  delete content.layout;

  return { words: JSON.stringify([content, heading?.translations ?? {}]), layout };
}

/**
 * What saving would change, band by band.
 *
 * Compared **as the contract would carry it** (`toInput`), not as the draft
 * holds it: a trailing space, or a French field emptied back to nothing,
 * is not a change the server would ever see, and listing it would teach
 * people to stop reading the list.
 */
export function changesSince(saved: StoryState, now: StoryState): readonly StoryChange[] {
  const changes: StoryChange[] = [];
  const before = bandsOnThePage(saved);
  const after = bandsOnThePage(now);

  for (const kind of now.order) {
    const was = before.includes(kind);
    const is = after.includes(kind);

    if (!was && !is) {
      continue;
    }

    if (was !== is) {
      changes.push({ band: kind, what: is ? 'added' : 'removed' });
      continue;
    }

    const then = headingOf(saved, kind);
    const today = headingOf(now, kind);

    if (rowsOf(saved, kind) !== rowsOf(now, kind) || then.words !== today.words) {
      changes.push({ band: kind, what: 'rewritten' });
    }

    if (then.layout !== today.layout) {
      changes.push({ band: kind, what: 'layout' });
    }
  }

  // Only among the bands on both pages: a band that arrived or left has
  // already been said, and would otherwise be said twice as a move.
  const kept = (list: readonly BandKind[], other: readonly BandKind[]) =>
    list.filter((kind) => other.includes(kind)).join(',');

  if (kept(before, after) !== kept(after, before)) {
    changes.push({ band: null, what: 'order' });
  }

  return changes;
}

export interface MissingField {
  readonly band: AuthoredBandKind;
  /** The row's place among its own kind, from 1, as the panel numbers it. */
  readonly row: number;
  readonly label: string;
}

/**
 * The required sentences nobody has written in English.
 *
 * English is what a page is published from (spec §11.2), so the server
 * refuses a save that lacks one. Said before the save, with the band and
 * the row, rather than after it as a refusal naming a field.
 */
export function missingEnglish(state: StoryState): readonly MissingField[] {
  const missing: MissingField[] = [];
  const seen: Partial<Record<AuthoredBandKind, number>> = {};

  for (const block of state.draft) {
    const row = (seen[block.block] ?? 0) + 1;
    seen[block.block] = row;

    for (const field of BAND_FIELDS[block.block]) {
      if (field.required && (block.fields[field.name] ?? EMPTY).en.trim() === '') {
        missing.push({ band: block.block, row, label: field.label });
      }
    }
  }

  return missing;
}

function sentences(state: StoryState): Translated[] {
  const all: Translated[] = [];

  for (const block of state.draft) {
    for (const field of BAND_FIELDS[block.block]) {
      // A code has no translation: it lives on the English.
      if (field.choices === undefined) {
        all.push(block.fields[field.name] ?? EMPTY);
      }
    }
  }

  for (const fields of Object.values(state.headings)) {
    for (const field of HEADING_FIELDS) {
      all.push(fields?.[field.name] ?? EMPTY);
    }
  }

  return all.filter((value) => value.en.trim() !== '');
}

/**
 * For each language, how many written sentences it does not yet say.
 *
 * Not an error: a missing translation is read in English, field by field.
 * It is a number because "is the Spanish page finished?" is asked before
 * every publication and the form could only answer it one field at a time.
 */
export function untranslated(state: StoryState): Record<Exclude<LocaleCode, 'en'>, number> {
  const written = sentences(state);
  const counts = { fr: 0, es: 0, de: 0, it: 0 };

  for (const locale of LOCALES) {
    if (locale === 'en') {
      continue;
    }

    counts[locale] = written.filter((value) => (value[locale] ?? '').trim() === '').length;
  }

  return counts;
}
