import { BAND_FIELDS } from '@/features/showcase/blocks/editors';
import { bandsInOrder, type AuthoredBandKind, type BandKind } from '@/features/showcase/blocks/meta';
import type { StoryTemplate } from '@/features/showcase/blocks/templates';
import type {
  Showcase,
  ShowcaseBandHeadings,
  ShowcaseBlock,
  ShowcaseBlockInput,
} from '@/queries/showcase';
import type { LocaleCode } from '@/i18n';
import type { Translated } from '@/ui/TranslatedField';

import { headingFieldsOf } from './BandHeading';

/**
 * The story as the console holds it while somebody is writing it.
 *
 * Out of `StoryScreen` since 2026-11-20, when a second reader arrived: the
 * preview draws the page from the same draft the form edits, and a draft
 * that lived inside one component could only be shown by that component.
 */

export const EMPTY: Translated = { en: '' };

/**
 * A band being edited: its fields in five languages, and a key.
 *
 * The key is local and never sent. A block's id is the server's to mint —
 * the story is replaced wholly on every save, so an id the console held on
 * to would name a row the save had just deleted.
 */
export interface DraftBlock {
  readonly key: string;
  readonly block: AuthoredBandKind;
  readonly fields: Record<string, Translated>;
  readonly assetId: string | null;
  /** Where it lives, from the server. Null until the page is published. */
  readonly image: string | null;
}

let minted = 0;

export function blank(block: AuthoredBandKind): DraftBlock {
  minted += 1;

  return {
    key: `new-${String(minted)}`,
    block,
    fields: Object.fromEntries(BAND_FIELDS[block].map((field) => [field.name, EMPTY])),
    assetId: null,
    image: null,
  };
}

/**
 * What a band starts with when it is added to a page (2026-11-22).
 *
 * **Enough rows to be drawn, already named.** Every band is drawn from its
 * first row except a comparison, which is drawn from two — so adding one
 * used to add a single nameless side, and a band the page did not show. The
 * operator had to guess that a second row was missing, find the button for
 * it, and name both before anything appeared. It now arrives as two sides
 * called Before and After, in the five languages, each waiting only for
 * what it shows.
 *
 * The names are content, not chrome: written here as the page's words, in
 * every language a page is read in, and rewritten like any other sentence.
 */
export function startOf(block: AuthoredBandKind): DraftBlock[] {
  if (block !== 'COMPARE') {
    return [blank(block)];
  }

  return SIDES.map((label) => {
    const side = blank(block);

    return { ...side, fields: { ...side.fields, label } };
  });
}

const side = (...said: readonly [string, string, string, string, string]): Translated => {
  const [en, fr, es, de, it] = said;

  return { en, fr, es, de, it };
};

const SIDES: readonly Translated[] = [
  side('Before', 'Avant', 'Antes', 'Vorher', 'Prima'),
  side('After', 'Après', 'Después', 'Nachher', 'Dopo'),
];

export function toDraft(block: ShowcaseBlock): DraftBlock {
  const kind = block.block;
  const content = block.content as Record<string, string | undefined>;
  const translations = block.translations as Record<string, Record<string, string | undefined> | undefined>;

  return {
    key: block.id,
    block: kind,
    assetId: block.asset_id ?? null,
    image: block.image ?? null,
    fields: Object.fromEntries(
      BAND_FIELDS[kind].map((field) => [
        field.name,
        {
          en: content[field.name] ?? '',
          fr: translations.fr?.[field.name] ?? '',
          es: translations.es?.[field.name] ?? '',
          de: translations.de?.[field.name] ?? '',
          it: translations.it?.[field.name] ?? '',
        },
      ]),
    ),
  };
}

/**
 * A band, as the contract takes it.
 *
 * `position` is the band's place among its own kind, minted from the order
 * on screen: dragging is not built, and a number nobody can see is better
 * derived than typed. In tens, `display_order`'s habit.
 *
 * A language that says nothing in a field is left out, which is how a
 * translation is removed — the server replaces the set.
 */
export function toInput(block: DraftBlock, index: number): ShowcaseBlockInput {
  const content: Record<string, string> = {};
  const translations: Record<string, Record<string, string>> = {};

  for (const field of BAND_FIELDS[block.block]) {
    const value = block.fields[field.name] ?? EMPTY;
    const english = value.en.trim();

    if (english !== '') {
      content[field.name] = english;
    }

    for (const locale of ['fr', 'es', 'de', 'it'] as const) {
      const written = (value[locale] ?? '').trim();

      if (written !== '') {
        translations[locale] = { ...translations[locale], [field.name]: written };
      }
    }
  }

  return {
    block: block.block,
    position: (index + 1) * 10,
    content,
    asset_id: block.assetId,
    ...(Object.keys(translations).length === 0 ? {} : { translations }),
  };
}

/** Every section's heading, in five languages, as the console holds them. */
export type HeadingDraft = Record<string, Record<string, Translated> | undefined>;

/**
 * The server's headings as a draft.
 *
 * A section the server did not name has never been retitled, and arrives as
 * nothing at all rather than as three empty strings — the form fills in the
 * blanks itself, and the difference matters on the way back out.
 */
export function headingsToDraft(bands: ShowcaseBandHeadings | undefined): HeadingDraft {
  const draft: HeadingDraft = {};

  for (const [block, heading] of Object.entries(bands ?? {})) {
    const content = (heading?.content ?? {}) as Record<string, string | undefined>;
    const translations = (heading?.translations ?? {}) as Record<
      string,
      Record<string, string | undefined> | undefined
    >;

    draft[block] = Object.fromEntries(
      headingFieldsOf(block as BandKind).map((field) => [
        field.name,
        {
          en: content[field.name] ?? '',
          fr: translations.fr?.[field.name] ?? '',
          es: translations.es?.[field.name] ?? '',
          de: translations.de?.[field.name] ?? '',
          it: translations.it?.[field.name] ?? '',
        },
      ]),
    );
  }

  return draft;
}

/**
 * The draft as the contract takes it.
 *
 * **A band that says nothing in any language is left out**, not sent empty:
 * the server stores no row for it, so sending one would be asking for a
 * state it does not keep, and the next read would disagree with what the
 * screen had sent. Left out is also exactly what "this band reads its
 * default words" means.
 */
export function headingsToInput(headings: HeadingDraft): ShowcaseBandHeadings {
  const written: Record<string, { content: Record<string, string>; translations: Record<string, Record<string, string>> }> = {};

  for (const [block, fields] of Object.entries(headings)) {
    const content: Record<string, string> = {};
    const translations: Record<string, Record<string, string>> = {};

    for (const field of headingFieldsOf(block as BandKind)) {
      const value = fields?.[field.name] ?? EMPTY;
      const english = value.en.trim();

      if (english !== '') {
        content[field.name] = english;
      }

      for (const locale of ['fr', 'es', 'de', 'it'] as const) {
        const said = (value[locale] ?? '').trim();

        if (said !== '') {
          translations[locale] = { ...translations[locale], [field.name]: said };
        }
      }
    }

    if (Object.keys(content).length > 0 || Object.keys(translations).length > 0) {
      written[block] = { content, translations };
    }
  }

  return written;
}


/**
 * A template, as a draft somebody can start editing.
 *
 * Only English is filled: a template is a place to start writing, and a
 * French sentence nobody chose would be published as though somebody had.
 */
export function draftFromTemplate(template: StoryTemplate): DraftBlock[] {
  return template.blocks.map((block) => ({
    ...blank(block.block),
    fields: Object.fromEntries(
      BAND_FIELDS[block.block].map((field) => [field.name, { en: block.content[field.name] ?? '' }]),
    ),
  }));
}

/**
 * A page with a template applied to it.
 *
 * The rows and the order are the template's. The headings are kept, and
 * only the layouts the template has an opinion about are changed: a band it
 * does not name keeps the layout and the title it had.
 */
export function withTemplate<S extends { readonly headings: HeadingDraft }>(
  state: S,
  template: StoryTemplate,
): S & { draft: DraftBlock[]; order: readonly BandKind[]; headings: HeadingDraft } {
  const headings: HeadingDraft = { ...state.headings };

  for (const [block, layout] of Object.entries(template.layouts ?? {})) {
    headings[block] = { ...(headings[block] ?? {}), layout: { en: layout } };
  }

  return { ...state, draft: draftFromTemplate(template), order: bandsInOrder(template.sections), headings };
}

/**
 * The draft as the page the public read would answer, in one language.
 *
 * **The same shape `GET /public/products/{code}/showcase` sends**, so the
 * preview goes through `contentFrom()` — the parser the real page uses —
 * and cannot draw something the page would not. A second renderer for the
 * preview would be a second opinion about what a band looks like.
 *
 * Field by field, falling back to English, exactly as
 * `ShowcaseBlock::contentIn()` does on the server: a half-translated band
 * previews as it will be read.
 */
export function draftAsPage(
  product: { code: string; name: string },
  draft: readonly DraftBlock[],
  order: readonly BandKind[],
  headings: HeadingDraft,
  locale: LocaleCode,
): Showcase {
  const said = (value: Translated | undefined): string => {
    const translated = (value?.[locale] ?? '').trim();

    return translated !== '' ? translated : (value?.en ?? '').trim();
  };

  const bands: Record<string, Record<string, string>> = {};

  for (const [block, fields] of Object.entries(headings)) {
    bands[block] = Object.fromEntries(
      Object.entries(fields ?? {}).map(([name, value]) => [name, said(value)]),
    );
  }

  return {
    product: { code: product.code, name: product.name, active: true },
    sections: [...order],
    bands,
    blocks: draft.map((block, index) => ({
      id: block.key,
      block: block.block,
      position: (index + 1) * 10,
      content: Object.fromEntries(
        BAND_FIELDS[block.block].map((field) => [
          field.name,
          // A code has no translation: it lives on the English.
          field.choices !== undefined ? (block.fields[field.name]?.en ?? '') : said(block.fields[field.name]),
        ]),
      ),
      image: block.image,
    })),
  };
}
