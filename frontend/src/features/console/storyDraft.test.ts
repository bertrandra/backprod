import { describe, expect, it } from 'vitest';

import { BAND_FIELDS } from '@/features/showcase/blocks/editors';
import { contentFrom } from '@/features/showcase/blocks/fromApi';
import { BAND_META, bandsInOrder, type BandKind } from '@/features/showcase/blocks/meta';
import { STORY_TEMPLATES } from '@/features/showcase/blocks/templates';

import { draftAsPage, draftFromTemplate, toInput, type DraftBlock } from './storyDraft';

const PLAN = { code: 'plan', name: 'Plan' };

const HEADLINE: DraftBlock = {
  key: 'b-1',
  block: 'HEADLINE',
  assetId: null,
  image: null,
  fields: {
    headline: { en: 'Draw a terrace', fr: 'Dessinez une terrasse' },
    subline: { en: 'And print the file.', fr: '' },
  },
};

/**
 * The preview is only worth having if it is the page: these go through the
 * page's own parser, so what they assert is what a stranger would read.
 */
describe('the draft, as the page a stranger would be sent', () => {
  it('reads a translated field in its language and the rest in English, field by field', () => {
    const french = contentFrom(draftAsPage(PLAN, [HEADLINE], bandsInOrder(), {}, 'fr'));

    // What `ShowcaseBlock::contentIn()` answers on the server: a French
    // headline over an English subline, not the whole English row.
    expect(french.headline[0]?.content.headline).toBe('Dessinez une terrasse');
    expect(french.headline[0]?.content.subline).toBe('And print the file.');
  });

  it('carries a band’s heading, and leaves an unwritten one to the band’s own words', () => {
    const page = contentFrom(
      draftAsPage(PLAN, [HEADLINE], bandsInOrder(), { STEPS: { title: { en: 'In three moves' } } }, 'en'),
    );

    expect(page.headings.STEPS?.title).toBe('In three moves');
    expect(page.headings.PROOF).toBeUndefined();
  });

  it('keeps a code on the English, because an icon has no French', () => {
    const problem: DraftBlock = {
      key: 'b-2',
      block: 'PROBLEM',
      assetId: null,
      image: null,
      fields: { title: { en: 'Hours lost', fr: 'Des heures perdues' }, icon: { en: 'clock' } },
    };

    const french = contentFrom(draftAsPage(PLAN, [problem], bandsInOrder(), {}, 'fr'));

    expect(french.problems[0]?.content.icon).toBe('clock');
  });
});

/**
 * A template that could not be saved would teach nobody anything, so each
 * one is held to what the endpoint requires rather than to what it looks
 * like: known fields only, every required one said, one headline.
 */
describe.each(STORY_TEMPLATES)('the template “$name”', (template) => {
  it('orders every section of the page exactly once', () => {
    const every = Object.keys(BAND_META) as BandKind[];

    expect([...template.sections].sort()).toEqual([...every].sort());
  });

  it('writes only fields its bands have, and every one they require', () => {
    for (const block of template.blocks) {
      const fields = BAND_FIELDS[block.block];

      for (const name of Object.keys(block.content)) {
        expect(fields.map((field) => field.name), `${block.block}.${name}`).toContain(name);
      }

      for (const field of fields.filter((candidate) => candidate.required)) {
        expect(block.content[field.name]?.trim() ?? '', `${block.block}.${field.name}`).not.toBe('');
      }
    }
  });

  it('has one headline and no band the endpoint refuses without an address', () => {
    const kinds = template.blocks.map((block) => block.block);

    expect(kinds.filter((kind) => kind === 'HEADLINE')).toHaveLength(1);
    expect(kinds).not.toContain('DEMO');
  });

  it('becomes a draft in English only, which the write sends with no translations', () => {
    const sent = draftFromTemplate(template).map(toInput);

    expect(sent).toHaveLength(template.blocks.length);
    expect(sent.every((block) => block.translations === undefined)).toBe(true);
    expect(sent[0]?.content).toMatchObject(template.blocks[0]?.content ?? {});
  });
});
