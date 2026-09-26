import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { TranslationsScreen } from './TranslationsScreen';

/**
 * The translation desk (2026-09-26).
 *
 * Three things are under test and only one of them is visible: that the filter
 * narrows on typing, that the English is beside every box, and — the one that
 * matters most — that saving one sentence does not delete another. The write
 * *replaces* the translation set, so a payload missing a language or a field is
 * a silent deletion, and it looks exactly like a successful save on screen.
 */
const FEATURE_NAME = {
  kind: 'feature',
  id: 'f-1',
  code: 'terrace_engine',
  product: null,
  field: 'name',
  source: 'Terrace engine',
  translations: { fr: 'Moteur de terrasse', es: 'Motor de terraza' },
};

const FEATURE_DESCRIPTION = {
  kind: 'feature',
  id: 'f-1',
  code: 'terrace_engine',
  product: null,
  field: 'description',
  source: 'Draws a terrace and works out what it costs.',
  // Nothing in French, which is the ordinary state of a catalogue somebody is
  // halfway through — and the row the desk exists to surface.
  translations: { es: 'Dibuja una terraza y calcula su coste.' },
};

const OFFER = {
  kind: 'offer',
  id: 'o-1',
  code: 'pro-monthly',
  product: 'plan',
  field: 'name',
  source: 'Terrace Pro monthly',
  translations: {},
};

const TEXTS = [FEATURE_NAME, FEATURE_DESCRIPTION, OFFER];

/**
 * A product's story, as the desk lists it (2026-09-26).
 *
 * `field` is a **path** — the band, its position, the band's own field — because
 * the record here is the whole story: `writeProductStory` replaces every band at
 * once, so `headline` alone would name one of several. `id` is the *product's*,
 * for the same reason: no route writes one band. And `product` is null, because
 * the story is the product's and `code` already says which one.
 */
const STORY_HEADLINE = {
  kind: 'showcase',
  id: 'p-plan',
  code: 'plan',
  product: null,
  field: 'HEADLINE.10.headline',
  source: 'Draw a terrace',
  translations: { es: 'Dibuja una terraza' },
};

const STORY_SUBLINE = {
  ...STORY_HEADLINE,
  field: 'HEADLINE.10.subline',
  source: 'And know what it costs.',
  translations: {},
};

const STORY_QUESTION = {
  ...STORY_HEADLINE,
  field: 'QUESTION.20.question',
  source: 'Does it export?',
  translations: {},
};

const STORY_ANSWER = {
  ...STORY_HEADLINE,
  field: 'QUESTION.20.answer',
  source: 'DXF and PDF.',
  translations: { fr: 'DXF et PDF.' },
};

const EVERYTHING = [...TEXTS, STORY_HEADLINE, STORY_SUBLINE, STORY_QUESTION, STORY_ANSWER];

/**
 * The same story as the operation that owns it answers it — which carries what
 * the desk's rows do not: the pictures, the positions, and the fields of every
 * other language.
 *
 * That difference is the whole reason the desk re-reads this before writing. A
 * merge built from the desk's own rows would send a story with no `asset_id`,
 * and the write replaces the story, so the picture would be gone.
 */
const STORY = {
  product: { id: 'p-plan', code: 'plan', name: 'Plan' },
  published_at: null,
  blocks: [
    {
      id: 'b-1',
      block: 'HEADLINE',
      position: 10,
      content: { headline: 'Draw a terrace', subline: 'And know what it costs.' },
      asset_id: 'a-1',
      image: '/api/v1/public/products/plan/showcase/assets/a-1',
      translations: { es: { headline: 'Dibuja una terraza' } },
    },
    {
      id: 'b-2',
      block: 'QUESTION',
      position: 20,
      content: { question: 'Does it export?', answer: 'DXF and PDF.' },
      asset_id: null,
      image: null,
      translations: { fr: { answer: 'DXF et PDF.' } },
    },
  ],
};

const ROUTE = { path: '/console/translations', initial: '/console/translations' } as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    'PATCH /api/v1/staff/catalogue/offers/{offerId}': { data: { offer: {} } },
    ...extra,
  });
}

/** The desk with the stories on it, and the story's own two operations. */
function storyClient(extra: Stubs = {}) {
  return recordingClient({
    'GET /api/v1/staff/translations': { data: { texts: EVERYTHING } },
    'GET /api/v1/staff/products/{productId}/showcase': { data: STORY },
    'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: STORY.blocks } },
    ...extra,
  });
}

async function shown() {
  await waitFor(() => expect(screen.getByTestId('translation-list')).toBeTruthy());
}

describe('the desk', () => {
  it('asks once, unpaginated, and names no product', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    const reads = requests.filter((request) => request.path === '/api/v1/staff/translations');

    expect(reads).toHaveLength(1);
    // The one list here with no cursor: the whole point is to count what is
    // missing across the set, and a page could not answer that.
    expect(reads[0]?.query).toBeUndefined();
  });

  it('shows the English beside every box, and never as an input', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    expect(
      screen.getByTestId('translation-feature-terrace_engine-name-source').textContent,
    ).toContain('Terrace engine');

    // The key and the fallback. An input here would look like a translation and
    // behave like a rename of the row.
    expect(screen.queryByDisplayValue('Terrace engine')).toBeNull();
  });

  it('opens on a language that is not English', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    const chooser = screen.getByTestId<HTMLSelectElement>('translation-locale');

    expect(chooser.value).not.toBe('en');
    // English is not even offered: correcting it is a rename, not a translation.
    expect([...chooser.options].map((option) => option.value)).not.toContain('en');
  });

  it('marks a box the chosen language has nothing in', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    expect(
      screen
        .getByTestId('translation-feature-terrace_engine-name')
        .getAttribute('data-missing'),
    ).toBe('false');
    expect(
      screen
        .getByTestId('translation-feature-terrace_engine-description')
        .getAttribute('data-missing'),
    ).toBe('true');
  });

  it('names the product an offer belongs to, and no product for a feature', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    // An offer's code is unique only within a product, so two really can both
    // be `pro-monthly`.
    expect(screen.getByTestId('product-of-pro-monthly').textContent).toBe('plan');
    expect(screen.queryByTestId('product-of-terrace_engine')).toBeNull();
  });
});

describe('the filter', () => {
  it('narrows as it is typed, with no second request', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'engine' } });

    await waitFor(() => expect(screen.queryByTestId('translate-offer-pro-monthly')).toBeNull());

    expect(screen.getByTestId('translate-feature-terrace_engine')).toBeTruthy();
    // Every sentence is already in hand, so a keystroke costs nothing.
    expect(requests.filter((request) => request.method === 'GET')).toHaveLength(1);
  });

  it('searches every language and not only the one on screen', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    // Spanish, while the desk is showing French: somebody who half-remembers a
    // word is looking for the row it is on.
    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'terraza' } });

    await waitFor(() => expect(screen.queryByTestId('translate-offer-pro-monthly')).toBeNull());

    expect(screen.getByTestId('translate-feature-terrace_engine')).toBeTruthy();
  });

  it('keeps only what the chosen language is missing when asked', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.click(screen.getByTestId('translation-only-missing'));

    await waitFor(() =>
      expect(screen.queryByTestId('translation-feature-terrace_engine-name')).toBeNull(),
    );

    // The description has no French, and the offer has nothing at all.
    expect(screen.getByTestId('translation-feature-terrace_engine-description')).toBeTruthy();
    expect(screen.getByTestId('translation-offer-pro-monthly-name')).toBeTruthy();
  });

  it('counts the whole set, not the part being shown', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'terraza' } });

    await waitFor(() =>
      expect(screen.getByTestId('translation-tally').textContent).toContain('of 3'),
    );

    // Two of three have no French. The number somebody came here for is how
    // much is left, which a count that shrank as they typed would not answer.
    expect(screen.getByTestId('translation-tally').textContent).toContain('2 without');
  });
});

describe('saving', () => {
  it('sends the whole set, so the other language is not deleted', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-feature-terrace_engine-name'), {
      target: { value: 'Moteur de terrasse Pro' },
    });
    fireEvent.submit(screen.getByTestId('translate-feature-terrace_engine'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const write = requests.find((request) => request.method === 'PATCH');

    expect(write?.body).toEqual({
      name: 'Terrace engine',
      translations: {
        fr: { name: 'Moteur de terrasse Pro', description: null },
        // The operation replaces the set. Sending French alone would delete
        // this, and the screen would look as though it had saved.
        es: { name: 'Motor de terraza', description: 'Dibuja una terraza y calcula su coste.' },
      },
    });
  });

  it('leaves the English description alone rather than clearing it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-feature-terrace_engine-description'), {
      target: { value: 'Dessine une terrasse.' },
    });
    fireEvent.submit(screen.getByTestId('translate-feature-terrace_engine'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const body = requests.find((request) => request.method === 'PATCH')?.body as
      | Record<string, unknown>
      | undefined;

    // Absent, not null: null would clear the English, and this screen does not
    // edit the English at all.
    expect(body).not.toHaveProperty('description');
    expect(body?.name).toBe('Terrace engine');
  });

  it('carries the product an offer is written in', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/catalogue/offers/{offerId}': { data: { offer: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });
    fireEvent.submit(screen.getByTestId('translate-offer-pro-monthly'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const write = requests.find((request) => request.method === 'PATCH');

    // A staff route resolves no product of its own — a platform role grants no
    // membership — so the product is named in the request or the write is
    // refused.
    expect(write?.query).toEqual({ product: 'plan' });
    expect(write?.body).toEqual({
      name: 'Terrace Pro monthly',
      translations: { fr: { name: 'Terrasse Pro mensuel' } },
    });
  });

  it('offers nothing to save until something is typed', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    const save = screen.getByTestId<HTMLButtonElement>('save-offer-pro-monthly');

    expect(save.disabled).toBe(true);

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });

    await waitFor(() => expect(save.disabled).toBe(false));
  });

  it('drops what was typed when the language changes', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });
    fireEvent.change(screen.getByTestId('translation-locale'), { target: { value: 'es' } });

    // Kept, a French draft would show under a Spanish label and save as
    // Spanish — a translation filed against the wrong language reads as
    // correct on screen and wrong to every customer.
    await waitFor(() =>
      expect(
        screen.getByTestId<HTMLInputElement>('translation-offer-pro-monthly-name').value,
      ).toBe(''),
    );
  });

  it('says so when the English was cleared and a translation was left behind', async () => {
    renderAtRoute(
      <TranslationsScreen />,
      clientFor({
        'GET /api/v1/staff/translations': {
          data: {
            texts: [
              FEATURE_NAME,
              { ...FEATURE_DESCRIPTION, source: '', translations: { fr: 'Dessine une terrasse.' } },
            ],
          },
        },
      }),
      ROUTE,
    );
    await shown();

    // Listed rather than hidden: hiding it would hide the inconsistency, and
    // would hand the next save a set with the French missing.
    expect(
      screen.getByTestId('translation-feature-terrace_engine-description-no-source'),
    ).toBeTruthy();
  });
});

/**
 * The product showcase (2026-09-26).
 *
 * The marketing copy a stranger reads before buying anything, and the one kind
 * of operator sentence the first desk left out — so its tally said a language
 * was finished while the shop window was still in English.
 *
 * Its write is what these tests are about. `renameFeature` replaces one
 * record's translation set; `writeProductStory` replaces a product's **whole
 * story** — every band, its English, its picture, its position, all four
 * languages. The desk's own rows carry none of the last three, so a save built
 * from them would delete the picture, renumber the page and wipe every other
 * language. What the screen holds is the sentences; what it re-reads is the
 * story.
 */
describe('a story', () => {
  it('is one card per product, with no product badge beside the code', async () => {
    const { client } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    // One card, not one per band: two cards of the same product would each be
    // built on a read the other had invalidated, and saving one would undo the
    // other on the same screen.
    expect(screen.getByTestId('translate-showcase-plan')).toBeTruthy();
    // The story *is* the product's, so the code already names it. A badge would
    // print the same word twice.
    expect(screen.queryByTestId('product-of-plan')).toBeNull();
  });

  it('counts its sentences in the same tally as the catalogue', async () => {
    const { client } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    // Seven sentences over three features, an offer and a story — one number,
    // which is the entire point of this screen.
    expect(screen.getByTestId('translation-tally').textContent).toContain('of 7');
    // Everything with no French: the feature's description, the offer, the
    // headline (Spanish only), the subline and the question.
    expect(screen.getByTestId('translation-tally').textContent).toContain('5 without');
  });

  it('re-reads the story at the moment it saves, and sends all of it back', async () => {
    const { client, requests } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    // The story is *not* read when the screen opens. The desk holds every
    // sentence already, and reading four products' stories to show a list would
    // be four requests for something nobody has asked to edit yet.
    expect(requests.filter((request) => request.method === 'GET')).toHaveLength(1);

    fireEvent.change(screen.getByTestId('translation-showcase-plan-HEADLINE.10.headline'), {
      target: { value: 'Dessinez une terrasse' },
    });
    fireEvent.submit(screen.getByTestId('translate-showcase-plan'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PUT')).toBe(true));

    const read = requests.findIndex(
      (request) =>
        request.method === 'GET' && request.path === '/api/v1/staff/products/{productId}/showcase',
    );
    const write = requests.findIndex((request) => request.method === 'PUT');

    // Read, then write, in that order: the desk is a screen somebody leaves
    // open while they work through a language, and a story read when it opened
    // is a story that may have had a band removed since.
    expect(read).toBeGreaterThan(-1);
    expect(write).toBeGreaterThan(read);

    expect(requests[write]?.body).toEqual({
      blocks: [
        {
          block: 'HEADLINE',
          position: 10,
          // The English, the picture and the position all travel back
          // untouched. The write replaces the story, so anything left out here
          // is deleted — and none of it is on the desk's own rows.
          content: { headline: 'Draw a terrace', subline: 'And know what it costs.' },
          asset_id: 'a-1',
          translations: {
            // Spanish kept, although nothing on screen was Spanish.
            es: { headline: 'Dibuja una terraza' },
            fr: { headline: 'Dessinez une terrasse' },
          },
        },
        {
          // A band nobody touched, carried whole. Dropped, the page would lose
          // a question and the operator would find out from a customer.
          block: 'QUESTION',
          position: 20,
          content: { question: 'Does it export?', answer: 'DXF and PDF.' },
          asset_id: null,
          translations: { fr: { answer: 'DXF et PDF.' } },
        },
      ],
    });
  });

  it('sends two bands in one write when two of its sentences were typed', async () => {
    const { client, requests } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-showcase-plan-HEADLINE.10.subline'), {
      target: { value: 'Et sachez ce qu’elle coûte.' },
    });
    fireEvent.change(screen.getByTestId('translation-showcase-plan-QUESTION.20.question'), {
      target: { value: 'Peut-on exporter ?' },
    });
    fireEvent.submit(screen.getByTestId('translate-showcase-plan'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PUT')).toBe(true));

    const writes = requests.filter((request) => request.method === 'PUT');

    // One call and not two. Two writes would each be built on a read the other
    // had already invalidated, and the second would undo the first.
    expect(writes).toHaveLength(1);

    const body = writes[0]?.body as { blocks: { translations: unknown }[] } | undefined;

    expect(body?.blocks[0]?.translations).toEqual({
      es: { headline: 'Dibuja una terraza' },
      fr: { subline: 'Et sachez ce qu’elle coûte.' },
    });
    expect(body?.blocks[1]?.translations).toEqual({
      fr: { answer: 'DXF et PDF.', question: 'Peut-on exporter ?' },
    });
  });

  it('removes a translation emptied, rather than saving a blank one', async () => {
    const { client, requests } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-showcase-plan-QUESTION.20.answer'), {
      target: { value: '  ' },
    });
    fireEvent.submit(screen.getByTestId('translate-showcase-plan'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PUT')).toBe(true));

    const body = requests.find((request) => request.method === 'PUT')?.body as
      | { blocks: { translations: unknown }[] }
      | undefined;

    // The language goes with its last field: the server drops a blank field and
    // keeps no row for an empty object, so sending either would be asking for a
    // state it does not store and the next read would disagree.
    expect(body?.blocks[1]?.translations).toEqual({});
  });

  it('refuses rather than re-creating a band that has gone', async () => {
    const { client, requests } = storyClient({
      'GET /api/v1/staff/products/{productId}/showcase': {
        // Somebody removed the headline while this screen was open.
        data: { ...STORY, blocks: [STORY.blocks[1]] },
      },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-showcase-plan-HEADLINE.10.headline'), {
      target: { value: 'Dessinez une terrasse' },
    });
    fireEvent.submit(screen.getByTestId('translate-showcase-plan'));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());

    // Re-creating it would resurrect a band the operator deleted — and with no
    // English in it, since this screen does not edit the English at all.
    expect(screen.getByRole('alert').textContent).toContain('no longer on the page');
    expect(requests.some((request) => request.method === 'PUT')).toBe(false);
  });

  it('keeps only the sentences the chosen language is missing', async () => {
    const { client } = storyClient();

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.click(screen.getByTestId('translation-only-missing'));

    await waitFor(() =>
      expect(screen.queryByTestId('translation-showcase-plan-QUESTION.20.answer')).toBeNull(),
    );

    // The answer has French; the other three sentences of the story do not.
    expect(screen.getByTestId('translation-showcase-plan-HEADLINE.10.headline')).toBeTruthy();
    expect(screen.getByTestId('translation-showcase-plan-HEADLINE.10.subline')).toBeTruthy();
    expect(screen.getByTestId('translation-showcase-plan-QUESTION.20.question')).toBeTruthy();
  });
});
