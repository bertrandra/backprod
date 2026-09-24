import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { StoryScreen } from './StoryScreen';

/**
 * The console's end of the product's story.
 *
 * What the tests here are about is the **contract with the page**: the
 * editor is driven by the same registry the bands render from, so the
 * forms it offers and the order it offers them in are not its own opinion.
 * A test that asserted a hard-coded list of bands would pass while the two
 * drifted, which is the whole thing §6 is trying to prevent.
 */
const PRODUCT = { id: 'p-1', code: 'plan', name: 'Plan' };

const HEADLINE = {
  id: 'b-1',
  block: 'HEADLINE',
  position: 10,
  content: { headline: 'Draw a terrace', subline: 'And print the file.' },
  translations: { fr: { headline: 'Dessinez une terrasse' } },
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/products/{productId}/showcase': {
      data: { product: PRODUCT, published_at: null, blocks: [HEADLINE] },
    },
    'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: [HEADLINE] } },
    'POST /api/v1/staff/products/{productId}/showcase/publish': { data: { published_at: '2026-09-24T10:00:00Z' } },
    ...extra,
  });
}

describe('the editor', () => {
  it('offers a form for every band the page renders, and in the page’s order', async () => {
    renderWith(<StoryScreen productId="p-1" />, clientFor());

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());

    // The order the page reads them in, from the registry — not a list
    // this screen keeps of its own. PRICING is absent because it is a
    // position in the order and reads the catalogue.
    const offered = [...screen.getByTestId('add-HEADLINE').parentElement!.children].map((button) =>
      button.getAttribute('data-testid'),
    );

    expect(offered).toEqual([
      'add-HEADLINE',
      'add-STEPS',
      'add-USE_CASE',
      'add-PROOF',
      'add-QUESTION',
    ]);
    expect(screen.queryByTestId('add-PRICING')).toBeNull();
  });

  it('will not offer a second headline, because a page has one', async () => {
    renderWith(<StoryScreen productId="p-1" />, clientFor());

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());

    expect(screen.getByTestId<HTMLButtonElement>('add-HEADLINE').disabled).toBe(true);
    expect(screen.getByTestId<HTMLButtonElement>('add-STEPS').disabled).toBe(false);
  });

  it('adds one band per click, even when the clicks land in one tick', async () => {
    renderWith(<StoryScreen productId="p-1" />, clientFor());

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());

    // Three clicks, three bands. With `setDraft([...draft, …])` all three
    // close over the same render's draft and the last one wins, so adding
    // three adds one — which is what clicking the real screen did before
    // the updater form went in.
    fireEvent.click(screen.getByTestId('add-STEPS'));
    fireEvent.click(screen.getByTestId('add-STEPS'));
    fireEvent.click(screen.getByTestId('add-STEPS'));

    await waitFor(() =>
      expect(screen.getByTestId('story-bands').querySelectorAll('[data-band-kind="STEPS"]')).toHaveLength(3),
    );
  });

  it('loads what the operator wrote, in every language', async () => {
    renderWith(<StoryScreen productId="p-1" />, clientFor());

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());

    // The English in the field, and the dots saying which other languages
    // say something — the gap is visible to the person who can close it.
    expect(screen.getByTestId<HTMLInputElement>('translated-b-1-headline').value).toBe('Draw a terrace');

    const written = screen.getByTestId('written-in-b-1-headline');
    expect(written.querySelector('[data-locale="fr"]')?.getAttribute('data-written')).toBe('true');
    expect(written.querySelector('[data-locale="de"]')?.getAttribute('data-written')).toBe('false');
  });
});

describe('saving', () => {
  it('sends the whole story, English apart from its translations', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products/{productId}/showcase': {
        data: { product: PRODUCT, published_at: null, blocks: [HEADLINE] },
      },
      'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: [HEADLINE] } },
    });

    renderWith(<StoryScreen productId="p-1" />, client);

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());

    fireEvent.click(screen.getByTestId('save-story'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({
      blocks: [
        {
          block: 'HEADLINE',
          // Minted from the order on screen, in tens: a number nobody can
          // see is better derived than typed.
          position: 10,
          content: { headline: 'Draw a terrace', subline: 'And print the file.' },
          translations: { fr: { headline: 'Dessinez une terrasse' } },
        },
      ],
    });
  });

  it('sends no id, because the story is replaced wholly', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products/{productId}/showcase': {
        data: { product: PRODUCT, published_at: null, blocks: [HEADLINE] },
      },
      'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: [HEADLINE] } },
    });

    renderWith(<StoryScreen productId="p-1" />, client);

    await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());
    fireEvent.click(screen.getByTestId('save-story'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    // An id the console held on to would name a row the save had just
    // deleted. The server mints them.
    expect(JSON.stringify(requests.find((r) => r.method === 'PUT')?.body)).not.toContain('b-1');
  });

  it('drops a language that says nothing, which is how a translation is removed', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products/{productId}/showcase': {
        data: { product: PRODUCT, published_at: null, blocks: [HEADLINE] },
      },
      'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: [HEADLINE] } },
    });

    renderWith(<StoryScreen productId="p-1" />, client);

    await waitFor(() => expect(screen.getByTestId('translated-b-1-headline')).toBeTruthy());

    fireEvent.click(screen.getByTestId('language-of-b-1-headline'));
    fireEvent.click(screen.getByTestId('language-fr-of-b-1-headline'));
    fireEvent.change(screen.getByTestId('translated-b-1-headline'), { target: { value: '' } });

    fireEvent.click(screen.getByTestId('save-story'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const body = requests.find((r) => r.method === 'PUT')?.body as { blocks: { translations?: unknown }[] };

    // The server replaces the set, so a language left out is one it
    // removes. Nothing here has to ask for a deletion.
    expect(body.blocks[0]?.translations).toBeUndefined();
  });
});

describe('publishing', () => {
  it('is a second act, and says which state the page is in', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products/{productId}/showcase': {
        data: { product: PRODUCT, published_at: null, blocks: [HEADLINE] },
      },
      'POST /api/v1/staff/products/{productId}/showcase/publish': { data: { published_at: '2026-09-24T10:00:00Z' } },
    });

    renderWith(<StoryScreen productId="p-1" />, client);

    await waitFor(() => expect(screen.getByTestId('story-state')).toBeTruthy());
    expect(screen.getByTestId('story-state').getAttribute('data-published')).toBe('false');

    fireEvent.click(screen.getByTestId('publish-story'));

    await waitFor(() => expect(requests.some((r) => r.method === 'POST')).toBe(true));

    // Writing did not run with it: four bands can be written over an
    // afternoon without a stranger reading the half-finished ones.
    expect(requests.some((r) => r.method === 'PUT')).toBe(false);
    expect(requests.find((r) => r.method === 'POST')?.body).toEqual({ published: true });
  });

  it('offers to take a published page down', async () => {
    renderWith(
      <StoryScreen productId="p-1" />,
      clientFor({
        'GET /api/v1/staff/products/{productId}/showcase': {
          data: { product: PRODUCT, published_at: '2026-09-24T10:00:00Z', blocks: [HEADLINE] },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('story-state')).toBeTruthy());

    expect(screen.getByTestId('story-state').getAttribute('data-published')).toBe('true');
    expect(screen.getByTestId('publish-story').textContent).toContain('Take it down');
  });
});
