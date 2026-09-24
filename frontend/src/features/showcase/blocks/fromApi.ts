import type { Showcase } from '@/queries/showcase';

import {
  NO_CONTENT,
  type ShowcaseContent,
  type ShowcaseImage,
  type ShowcaseRow,
} from './content';

/**
 * The contract's blocks, as the bands read them.
 *
 * One function, at the edge, because the two shapes differ on purpose. The
 * API answers **a flat list of blocks in order** — which is what a database
 * has and what a console edits — and a band wants *its own* rows. Sorting
 * and grouping once here is what lets every band take a plain array and
 * stay a component somebody can render in a test with three lines of
 * fixture.
 *
 * **A band whose fields are missing is dropped, not rendered empty.** The
 * server refuses a block without its required English, so this only fires
 * for a row written before a band's shape changed — and a page that showed
 * a heading with nothing under it would look broken in a way a missing
 * band does not (spec §6).
 */
export function contentFrom(showcase: Showcase | null | undefined): ShowcaseContent {
  // `Array.isArray` as well as the null check, because **this page must not
  // fall over on a bad answer**. It is the first thing a stranger sees and
  // the one screen with no session behind it to blame; an answer whose
  // `blocks` is missing — an older server, a proxy that rewrote something,
  // a stub in a test — has to render as "nothing written yet", which is a
  // page, and not as a blank screen, which is an outage.
  if (showcase === null || showcase === undefined || !Array.isArray(showcase.blocks)) {
    return NO_CONTENT;
  }

  const ordered = [...showcase.blocks].sort((a, b) => a.position - b.position);
  const pick = <T>(kind: string, read: (content: Record<string, unknown>) => T | null) =>
    ordered.flatMap((block): ShowcaseRow<T>[] => {
      if (block.block !== kind) {
        return [];
      }

      const content = read(block.content);

      return content === null ? [] : [{ id: block.id, content, image: pictureOf(block) }];
    });

  return {
    headline: pick('HEADLINE', (c) => {
      const headline = text(c.headline);

      return headline === null ? null : { headline, subline: text(c.subline) };
    }),
    steps: pick('STEPS', (c) => {
      const title = text(c.title);

      return title === null ? null : { title, body: text(c.body) };
    }),
    useCases: pick('USE_CASE', (c) => {
      const who = text(c.who);

      return who === null ? null : { who, before: text(c.before), after: text(c.after) };
    }),
    proof: pick('PROOF', (c) => {
      const caption = text(c.caption);

      return caption === null ? null : { caption };
    }),
    questions: pick('QUESTION', (c) => {
      const question = text(c.question);
      const answer = text(c.answer);

      return question === null || answer === null ? null : { question, answer };
    }),
  };
}

/**
 * Whether the product this story belongs to is retired.
 *
 * Beside {@see contentFrom} and guarded the same way, because the two
 * containers ask it and `showcase.product.active` is one `?.` away from
 * throwing on an answer that is not the shape it claims. A page that fell
 * over on a bad answer would be an outage on the one screen with no session
 * behind it to blame — which is exactly how this was found.
 *
 * Unknown is **not** retired: saying "No longer sold" about a product
 * nobody has retired would be worse than saying nothing, and the prices
 * band has an honest answer for an empty catalogue already.
 */
export function isRetired(showcase: Showcase | null | undefined): boolean {
  return (
    showcase !== null &&
    showcase !== undefined &&
    typeof showcase.product === 'object' &&
    showcase.product !== null &&
    showcase.product.active === false
  );
}

/**
 * The picture a band carries, with the words that describe it.
 *
 * **`alt` is a band field**, not a column on the picture, so it goes
 * through the same translation mechanism as every sentence on the page
 * rather than needing a second one of its own. Empty where nobody wrote
 * one, which `ShowcaseImageFrame` reads as decorative — the honest answer
 * for a screenshot whose caption is already beside it.
 *
 * The **ratio is not known here** and is not guessed: the server stores
 * bytes, not dimensions. The frame reserves 16:10 so nothing jumps while
 * the image loads, and the image fills it. Measuring on upload would be a
 * column and an image library for a box that is right either way.
 */
function pictureOf(block: { image?: string | null; content: Record<string, unknown> }): ShowcaseImage | null {
  const url = typeof block.image === 'string' && block.image !== '' ? block.image : null;

  if (url === null) {
    return null;
  }

  return { url, alt: text(block.content.alt) ?? '', ratio: 16 / 10 };
}

/** A field that says something, or nothing at all. Never an empty string. */
function text(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}
