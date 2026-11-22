import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, type Stubs } from '@/test-utils';

import { StoryScreen } from './StoryScreen';
import { changesSince, missingEnglish, untranslated, type StoryState } from './storyReview';
import { blank, toDraft } from './storyDraft';

const PRODUCT = { id: 'p-1', code: 'plan', name: 'Plan' };

const row = (id: string, block: string, position: number, content: Record<string, string>, translations = {}) => ({
  id,
  block,
  position,
  content,
  translations,
  asset_id: null,
  image: null,
});

const BLOCKS = [
  row('b-1', 'HEADLINE', 10, { headline: 'Draw a terrace', subline: 'And print the file.' }, { fr: { headline: 'Dessinez une terrasse' } }),
  row('b-2', 'STEPS', 10, { title: 'Draw the parcel', body: 'From the land registry.' }),
  row('b-3', 'STEPS', 20, { title: 'The terrace follows', body: 'To scale.' }),
  row('b-4', 'QUESTION', 10, { question: 'Can I cancel?', answer: 'Whenever you like.' }),
];

function open(extra: Stubs = {}, publishedAt: string | null = null) {
  const recording = recordingClient({
    'GET /api/v1/staff/products/{productId}/showcase': {
      data: { product: PRODUCT, published_at: publishedAt, blocks: BLOCKS },
    },
    'PUT /api/v1/staff/products/{productId}/showcase': { data: { blocks: BLOCKS } },
    'GET /api/v1/public/offers': { data: { product: PRODUCT, offers: [] } },
    ...extra,
  });

  renderWith(<StoryScreen productId="p-1" product={PRODUCT} />, recording.client);

  return recording;
}

async function editor() {
  await waitFor(() => expect(screen.getByTestId('story-bands')).toBeTruthy());
  fireEvent.click(screen.getByTestId('open-studio'));
  await waitFor(() => expect(inFrame()?.querySelector('[data-testid="showcase-headline"]')).toBeTruthy());
}

const inFrame = () => screen.getByTestId<HTMLIFrameElement>('preview-frame').contentDocument;

const structure = () =>
  [...screen.getByTestId('editor-structure').querySelectorAll('[data-structure]')].map((item) =>
    item.getAttribute('data-structure'),
  );

/**
 * The page editor (2026-11-21). What is claimed here is what makes it an
 * editor and not a form with a picture beside it: the page is what is
 * clicked and typed in, a gesture can be taken back, and a save says what
 * it would do before it does it.
 */
describe('the page editor', () => {
  it('lists the bands a reader meets, and not the ones nobody wrote', async () => {
    open();
    await editor();

    // Prices is there with no row of its own: it reads the catalogue.
    expect(structure()).toEqual(['HEADLINE', 'STEPS', 'PRICING', 'QUESTION']);
    expect(screen.getByTestId('panel-page')).toBeTruthy();
  });

  it('chooses a band by clicking it on the page', async () => {
    open();
    await editor();

    fireEvent.click(inFrame()!.querySelector('[data-band="how"] li')!);

    expect(screen.getByTestId('panel-STEPS')).toBeTruthy();
    expect(screen.getByTestId('structure-STEPS').getAttribute('aria-current')).toBe('true');
    // Outlined on the page itself, inside the frame.
    expect(inFrame()?.querySelector('[data-testid="canvas-chosen"]')?.getAttribute('data-anchor')).toBe('how');
  });

  it('rewrites a sentence where it is read, in the language being read', async () => {
    const { requests } = open();
    await editor();

    const headline = inFrame()!.querySelector<HTMLElement>('[data-testid="showcase-headline"]')!;

    fireEvent.doubleClick(headline);
    expect(headline.hasAttribute('data-rewriting')).toBe(true);

    headline.textContent = 'Draw a pergola';
    fireEvent.blur(headline);

    await waitFor(() =>
      expect(inFrame()?.querySelector('[data-testid="showcase-headline"]')?.textContent).toBe('Draw a pergola'),
    );

    // In French, the French sentence is the one rewritten: the English
    // under it is what the page is published from, and is left alone.
    fireEvent.change(screen.getByTestId('preview-language'), { target: { value: 'fr' } });

    await waitFor(() =>
      expect(inFrame()?.querySelector('[data-testid="showcase-headline"]')?.textContent).toBe('Dessinez une terrasse'),
    );

    const french = inFrame()!.querySelector<HTMLElement>('[data-testid="showcase-headline"]')!;

    fireEvent.doubleClick(french);
    french.textContent = 'Dessinez une pergola';
    fireEvent.blur(french);

    fireEvent.click(screen.getByTestId('editor-save'));
    fireEvent.click(screen.getByTestId('confirm-save'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const sent = requests.find((r) => r.method === 'PUT')?.body as { blocks: { content: object; translations?: object }[] };

    expect(sent.blocks[0]?.content).toEqual({ headline: 'Draw a pergola', subline: 'And print the file.' });
    expect(sent.blocks[0]?.translations).toEqual({ fr: { headline: 'Dessinez une pergola' } });
  });

  it('leaves a sentence alone when the rewriting is abandoned, or emptied', async () => {
    open();
    await editor();

    const headline = inFrame()!.querySelector<HTMLElement>('[data-testid="showcase-headline"]')!;

    fireEvent.doubleClick(headline);
    headline.textContent = 'Something else';
    fireEvent.keyDown(headline, { key: 'Escape' });
    fireEvent.blur(headline);

    expect(headline.textContent).toBe('Draw a terrace');

    fireEvent.doubleClick(headline);
    headline.textContent = '';
    fireEvent.blur(headline);

    expect(headline.textContent).toBe('Draw a terrace');
    expect(screen.getByTestId('editor-state').getAttribute('data-dirty')).toBe('false');
  });

  it('takes a gesture back, and gives it again', async () => {
    open();
    await editor();

    fireEvent.click(screen.getByTestId('structure-QUESTION'));
    fireEvent.click(screen.getByTestId('remove-band'));

    expect(structure()).toEqual(['HEADLINE', 'STEPS', 'PRICING']);

    fireEvent.click(screen.getByTestId('editor-undo'));
    expect(structure()).toEqual(['HEADLINE', 'STEPS', 'PRICING', 'QUESTION']);

    fireEvent.click(screen.getByTestId('editor-redo'));
    expect(structure()).toEqual(['HEADLINE', 'STEPS', 'PRICING']);
  });

  it('moves a band past the next one a reader would see, not the next of fifteen', async () => {
    const { requests } = open();
    await editor();

    fireEvent.click(screen.getByTestId('structure-QUESTION'));
    fireEvent.click(screen.getByTestId('move-band-up'));

    expect(structure()).toEqual(['HEADLINE', 'STEPS', 'QUESTION', 'PRICING']);

    fireEvent.click(screen.getByTestId('editor-save'));

    // Said before it is done: the order changed, and nothing else did.
    const changes = [...screen.getByTestId('review-changes').children].map((item) => item.getAttribute('data-change'));

    expect(changes).toEqual(['order']);

    fireEvent.click(screen.getByTestId('confirm-save'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const sent = requests.find((r) => r.method === 'PUT')?.body as { sections: string[] };

    expect(sent.sections.indexOf('QUESTION')).toBeLessThan(sent.sections.indexOf('PRICING'));
    expect(sent.sections).toHaveLength(15);
  });

  it('adds a band from the library, after the one being looked at', async () => {
    open();
    await editor();

    fireEvent.click(screen.getByTestId('structure-HEADLINE'));
    fireEvent.click(screen.getByTestId('open-library'));

    const library = within(screen.getByTestId('band-library'));

    // What is already on the page grows from its own panel.
    expect(library.getByTestId<HTMLButtonElement>('add-STEPS').disabled).toBe(true);
    expect(library.queryByTestId('add-PRICING')).toBeNull();

    fireEvent.click(library.getByTestId('add-STATS'));

    expect(screen.queryByTestId('band-library')).toBeNull();
    expect(structure()).toEqual(['HEADLINE', 'STATS', 'STEPS', 'PRICING', 'QUESTION']);
    expect(screen.getByTestId('panel-STATS')).toBeTruthy();
  });

  it('will not save a page with a required sentence missing, and says which', async () => {
    open();
    await editor();

    fireEvent.click(screen.getByTestId('open-library'));
    fireEvent.click(screen.getByTestId('add-STATS'));
    fireEvent.click(screen.getByTestId('editor-save'));

    expect(screen.getByTestId('review-missing').textContent).toContain('In numbers 1');
    expect(screen.getByTestId<HTMLButtonElement>('confirm-save').disabled).toBe(true);
  });

  it('chooses a layout with a button, and stores the usual one as no choice', async () => {
    const { requests } = open();
    await editor();

    fireEvent.click(screen.getByTestId('structure-STEPS'));

    expect(screen.getByTestId('layout-columns').getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(screen.getByTestId('layout-timeline'));
    expect(screen.getByTestId('layout-timeline').getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(screen.getByTestId('editor-save'));
    fireEvent.click(screen.getByTestId('confirm-save'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    expect((requests.find((r) => r.method === 'PUT')?.body as { bands: object }).bands).toEqual({
      STEPS: { content: { layout: 'timeline' }, translations: {} },
    });
  });

  it('hides the page’s menu from the page panel, as a code on the headline’s heading', async () => {
    const { requests } = open();
    await editor();

    expect(screen.getByTestId('menu-shown').getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(screen.getByTestId('menu-hidden'));
    expect(screen.getByTestId('menu-hidden').getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(screen.getByTestId('editor-save'));
    fireEvent.click(screen.getByTestId('confirm-save'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    expect((requests.find((r) => r.method === 'PUT')?.body as { bands: object }).bands).toEqual({
      HEADLINE: { content: { menu: 'hidden' }, translations: {} },
    });
  });

  it('offers the product’s demonstrations by name where a band embeds one', async () => {
    const { requests } = open({
      'GET /api/v1/staff/configuration/demonstrations': {
        data: {
          source: 'admin/vitrine/{n}',
          embed: '?mode=demo&file={n}',
          demonstrations: [
            { number: 1, name: 'The plot', embed_url: 'https://plan.example.test/?mode=demo&file=1' },
            { number: 4, name: 'The plot, with the pool', embed_url: 'https://plan.example.test/?mode=demo&file=4' },
          ],
        },
      },
    });
    await editor();

    fireEvent.click(screen.getByTestId('open-library'));
    fireEvent.click(screen.getByTestId('add-DEMO'));

    const picker = await screen.findByLabelText<HTMLSelectElement>('Choose a demonstration');

    expect([...picker.options].map((option) => option.textContent)).toEqual([
      'None',
      '1 — The plot',
      '4 — The plot, with the pool',
    ]);

    fireEvent.change(picker, { target: { value: 'https://plan.example.test/?mode=demo&file=4' } });

    // It fills the address field, which stays an ordinary field: the list
    // is a way to write it, not a second place it is kept.
    expect(screen.getByDisplayValue('https://plan.example.test/?mode=demo&file=4')).toBeTruthy();
    expect(picker.value).toBe('https://plan.example.test/?mode=demo&file=4');

    fireEvent.change(screen.getByLabelText('Caption'), { target: { value: 'The pool, in 3D' } });
    fireEvent.click(screen.getByTestId('editor-save'));
    fireEvent.click(screen.getByTestId('confirm-save'));
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const sent = requests.find((r) => r.method === 'PUT')?.body as { blocks: { block: string; content: Record<string, string> }[] };

    expect(sent.blocks.find((block) => block.block === 'DEMO')?.content.embed_url).toBe(
      'https://plan.example.test/?mode=demo&file=4',
    );
  });

  it('draws no list where the product answers for none, and keeps the address field', async () => {
    open({
      'GET /api/v1/staff/configuration/demonstrations': { data: { source: '', embed: '', demonstrations: [] } },
    });
    await editor();

    fireEvent.click(screen.getByTestId('open-library'));
    fireEvent.click(screen.getByTestId('add-DEMO'));

    expect(await screen.findByLabelText('Address to embed')).toBeTruthy();
    expect(screen.queryByLabelText('Choose a demonstration')).toBeNull();
  });

  it('says a published page changes for its visitors when it is saved', async () => {
    open({}, '2026-11-19T17:42:00Z');
    await editor();

    fireEvent.click(screen.getByTestId('structure-QUESTION'));
    fireEvent.click(screen.getByTestId('remove-band'));
    fireEvent.click(screen.getByTestId('editor-save'));

    expect(screen.getByTestId('save-review').textContent).toContain('saving changes what visitors read');
    expect(screen.getByTestId('confirm-save').textContent).toBe('Save and put online');
  });

  it('has nothing to save until something changed', async () => {
    open();
    await editor();

    expect(screen.getByTestId<HTMLButtonElement>('editor-save').disabled).toBe(true);
    expect(screen.getByTestId<HTMLButtonElement>('editor-undo').disabled).toBe(true);
  });
});

describe('what a save would change', () => {
  const state = (): StoryState => ({
    draft: BLOCKS.map((block) => toDraft(block as never)),
    order: ['HEADLINE', 'STEPS', 'PRICING', 'QUESTION'],
    headings: {},
  });

  it('finds nothing in a space nobody would see', () => {
    const now = state();
    const first = now.draft[0]!;
    const spaced = { ...first, fields: { ...first.fields, headline: { ...first.fields.headline!, en: 'Draw a terrace ' } } };

    expect(changesSince(state(), { ...now, draft: [spaced, ...now.draft.slice(1)] })).toEqual([]);
  });

  it('says a band arrived, left, was rewritten or laid out differently', () => {
    const before = state();
    const after: StoryState = {
      ...before,
      order: ['HEADLINE', 'STATS', 'STEPS', 'PRICING', 'QUESTION'],
      draft: [...before.draft.filter((block) => block.block !== 'QUESTION'), blank('STATS')],
      headings: { STEPS: { layout: { en: 'timeline' } }, PRICING: { title: { en: 'What it costs' } } },
    };

    expect(changesSince(before, after)).toEqual([
      { band: 'STATS', what: 'added' },
      { band: 'STEPS', what: 'layout' },
      { band: 'PRICING', what: 'rewritten' },
      { band: 'QUESTION', what: 'removed' },
    ]);
  });

  it('names the required sentence nobody wrote, by band and row', () => {
    const now = state();

    expect(missingEnglish(now)).toEqual([]);
    expect(missingEnglish({ ...now, draft: [...now.draft, blank('STEPS')] })).toEqual([
      { band: 'STEPS', row: 3, label: 'Step' },
    ]);
  });

  it('counts, per language, the written sentences it does not say', () => {
    // Eight sentences are written in English; French says one of them.
    expect(untranslated(state())).toEqual({ fr: 7, es: 8, de: 8, it: 8 });
  });
});
