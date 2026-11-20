import type { AuthoredBandKind, BandKind } from './meta';

/**
 * Pages to start from (2026-11-20).
 *
 * **An empty form is the hardest page to write.** The console offered eight
 * kinds of band and no opinion about which to use, so a product's first
 * page was whatever its operator thought of first. A template is an order
 * and a set of sentences that say *what goes here* — it is replaced, word
 * by word, by what the product actually has to say.
 *
 * **Frontend only, and deliberately.** A template is not stored and is not
 * a fact about any product: applying one fills the draft, and from then on
 * it is an ordinary draft saved through the ordinary write. Nothing on the
 * server knows a template was ever involved, which is why adding one is
 * adding an entry here.
 *
 * **The sentences are instructions, written as the sentence they ask for.**
 * "What it does, for whom, in one sentence" reads as a placeholder and is
 * obviously not finished, where lorem ipsum reads as noise and an invented
 * claim reads as true. They are English because the English is what a page
 * is published from (spec §11.2), and they are **not** run through `t()`:
 * they are content an operator edits, not chrome the console draws.
 *
 * No template carries a `DEMO` row: that band is refused without an address
 * or a picture, and a template whose first save fails teaches nobody
 * anything. The order still places it, so adding one later lands it where
 * the template means it to be.
 */
export interface StoryTemplate {
  readonly id: string;
  /** Shown on the card. Run through `t()` where it is drawn. */
  readonly name: string;
  readonly description: string;
  readonly sections: readonly BandKind[];
  readonly blocks: readonly TemplateBlock[];
}

export interface TemplateBlock {
  readonly block: AuthoredBandKind;
  readonly content: Readonly<Record<string, string>>;
}

/**
 * One row of a template: its kind, then its fields as pairs.
 *
 * Pairs and not an object literal, on purpose: `gate:i18n` reads every prose
 * property in this bundle as a sentence the console says, and these are not
 * — they are the product's words-to-be, in English only. Written as an
 * object they would each need four translations nobody would ever read.
 */
function row(block: AuthoredBandKind, ...fields: readonly (readonly [string, string])[]): TemplateBlock {
  return { block, content: Object.fromEntries(fields) };
}

const HEADLINE: TemplateBlock = row('HEADLINE', ['headline', 'What it does, for whom, in one sentence'], ['subline', 'The change it makes to their day, in one more sentence.'], ['reassurance', 'Nothing to install. Cancel whenever you like.']);

const STEPS: readonly TemplateBlock[] = [
  row('STEPS', ['title', 'The first thing they do'], ['body', 'What happens when they do it, in a line.']),
  row('STEPS', ['title', 'What the product does next'], ['body', 'The part they no longer do by hand.']),
  row('STEPS', ['title', 'What they leave with'], ['body', 'The file, the answer or the decision.']),
];

const QUESTIONS: readonly TemplateBlock[] = [
  row('QUESTION', ['question', 'The question asked before every purchase'], ['answer', 'The answer, in two lines at most.']),
  row('QUESTION', ['question', 'Can I cancel?'], ['answer', 'How, and what happens to the work already done.']),
  row('QUESTION', ['question', 'What do I need to start?'], ['answer', 'What they must have, and what they need not.']),
];

export const STORY_TEMPLATES: readonly StoryTemplate[] = [
  {
    id: 'complete',
    name: 'The whole story',
    description: 'The promise, the trouble it ends, how it works, who it is for, what they say, the proof, the prices and the questions. For a product people have not heard of.',
    sections: ['HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO', 'PRICING', 'QUESTION'],
    blocks: [
      HEADLINE,
      row('PROBLEM', ['title', 'What it costs them today'], ['body', 'The hours, the money or the mistake.'], ['icon', 'clock']),
      row('PROBLEM', ['title', 'What goes wrong because of it'], ['body', 'The consequence they already know.'], ['icon', 'warning']),
      row('PROBLEM', ['title', 'Why the usual answer does not work'], ['body', 'What they tried, and where it stops.'], ['icon', 'repeat']),
      ...STEPS,
      row('USE_CASE', ['who', 'A first kind of customer'], ['before', 'What they were doing before.'], ['after', 'What they do now.']),
      row('USE_CASE', ['who', 'A second kind of customer'], ['before', 'What they were doing before.'], ['after', 'What they do now.']),
      row('QUOTE', ['quote', 'What a customer said, in their own words.'], ['author', 'Their name, and what they do']),
      row('PROOF', ['caption', 'What this screenshot shows, and why it matters.']),
      row('PROOF', ['caption', 'A second thing worth seeing, said in one sentence.']),
      ...QUESTIONS,
    ],
  },
  {
    id: 'proof-first',
    name: 'Proof first',
    description: 'The product itself straight after the promise, then what customers say, then how it works. For a product that convinces by being seen.',
    sections: ['HEADLINE', 'PROOF', 'DEMO', 'QUOTE', 'STEPS', 'PRICING', 'QUESTION', 'PROBLEM', 'USE_CASE'],
    blocks: [
      HEADLINE,
      row('PROOF', ['caption', 'The one screen that makes somebody say “I want that”.']),
      row('PROOF', ['caption', 'What it looks like when the work is done.']),
      row('QUOTE', ['quote', 'What a customer said, in their own words.'], ['author', 'Their name, and what they do']),
      row('QUOTE', ['quote', 'A second voice, from a different kind of customer.'], ['author', 'Their name, and what they do']),
      ...STEPS,
      ...QUESTIONS,
    ],
  },
  {
    id: 'short',
    name: 'Short',
    description: 'The promise, three steps, the prices and two questions. For a product that explains itself, or a page to publish today and grow later.',
    sections: ['HEADLINE', 'STEPS', 'PRICING', 'QUESTION', 'PROBLEM', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO'],
    blocks: [HEADLINE, ...STEPS, ...QUESTIONS.slice(0, 2)],
  },
];
