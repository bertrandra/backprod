import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Offer } from '@/queries/catalogue';

import { Showcase } from './Showcase';
import type { ShowcaseContent } from './blocks/content';
import { contentFrom } from './blocks/fromApi';
import { BAND_META, bandsInOrder } from './blocks/meta';
import { BAND_VIEWS } from './blocks/registry';
import { playerOf } from './blocks/VideoBand';

/**
 * The product's story, as a page.
 *
 * No client and no router: `Showcase` takes what it is given and renders
 * it, which is the property that lets a stranger's container and a
 * member's differ in nothing but where the rows came from.
 */
const OFFER = {
  id: 'offer-1',
  code: 'pro-monthly',
  name: 'Pro, monthly',
  plan: { id: 'plan-1', code: 'pro', name: 'Pro', rank: 20 },
  version: {
    id: 'v-1',
    version: 1,
    billing_period: 'MONTHLY' as const,
    price: { minor_units: 3900, currency: 'EUR' },
    valid_from: '2026-01-01T00:00:00Z',
    valid_until: null,
    // Not the free period, and what it commits to (2026-09-27). Both are the
    // contract's, so a fixture that left them out would be a shop window
    // describing an offer no server can send.
    freemium: false,
    terms: {
      term_months: null,
      commitment_months: 0,
      cancellation_policy: 'ANYTIME' as const,
      renewal: 'AUTO_RENEW' as const,
      early_termination: 'FREE' as const,
      notice_days: 0,
    },
    grants: [],
  },
};

const row = <T,>(id: string, content: T) => ({ id, content, image: null });

const FULL: ShowcaseContent = {
  headline: [
    row('h', {
      headline: 'Draw a terrace in three minutes',
      subline: 'And print the file.',
      reassurance: null,
    }),
  ],
  problems: [
    row('p1', { title: 'Half a day per job', body: 'Measuring, then redrawing it.', icon: 'clock' }),
  ],
  quotes: [row('q1', { quote: 'The quote goes out the same evening.', author: 'A paving contractor' })],
  headings: {},
  steps: [row('s1', { title: 'Draw the parcel', body: null }), row('s2', { title: 'The terrace follows', body: null })],
  useCases: [row('u1', { who: 'A landscaper', before: 'An afternoon of redrawing', after: 'Three minutes' })],
  proof: [row('p1', { caption: 'The plan, as it prints' })],
  demos: [row('d1', { caption: 'The terrace, in three dimensions', embedUrl: 'https://plan.example/?x={width}&y={height}', ratio: '4:3' })],
  questions: [row('q1', { question: 'Can I cancel?', answer: 'Yes, at the end of the period.' })],
  trust: [row('t1', { name: 'Atelier Durand' })],
  stats: [row('n1', { value: '3 min', label: 'to a printable plan', detail: null })],
  features: [row('f1', { title: 'Exports to DXF', body: 'With its layers.', icon: 'layers' as const })],
  cta: [row('c1', { title: 'Draw your first terrace', body: null })],
  tour: [row('o1', { title: 'The cadastral plot', body: 'With its real boundaries.' })],
  compare: [
    row('k1', { label: 'Before', caption: null, embedUrl: 'https://plan.example.test/?file=1', ratio: null }),
    row('k2', { label: 'After', caption: null, embedUrl: 'https://plan.example.test/?file=2', ratio: null }),
  ],
  // Everything but the news: a line above the page, drawn there whatever
  // the order says, so it has its own test below.
  announce: [],
  video: [row('v', { caption: 'Two minutes of Plan', videoUrl: 'https://vimeo.com/76979871' })],
  versus: [row('vs', { criterion: 'Your plot in 3D', note: null, ours: 'yes', others: ['no', null, null] })],
  hotspots: [
    { id: 'hs', content: { title: 'The terrace', body: null, x: 40, y: 40 }, image: { url: '/plot.webp', alt: 'The plot', ratio: 1.6 } },
  ],
};

/** The compiled order, without the news, which is drawn above it all. */
const ORDERED = () => bandsInOrder().filter((kind) => kind !== 'ANNOUNCE');

const EMPTY: ShowcaseContent = {
  headline: [],
  problems: [],
  steps: [],
  useCases: [],
  quotes: [],
  proof: [],
  demos: [],
  questions: [],
  trust: [],
  stats: [],
  features: [],
  cta: [],
  tour: [],
  compare: [],
  announce: [],
  video: [],
  versus: [],
  hotspots: [],
  headings: {},
};

/**
 * A picture on a band that had none until 2026-09-28.
 *
 * `pictureOf` has always read `image` off every block, so the rows carried
 * one all along and only two bands rendered it. What these assert is that
 * the other two now do — and that a band with no picture still draws no
 * frame, because the alternative is a grey box where an operator simply had
 * nothing to show.
 */
const pictured = <T,>(id: string, content: T, alt: string) => ({
  id,
  content,
  image: { url: `https://example.test/${id}.png`, alt, ratio: 16 / 10 },
});

function renderStory(content: ShowcaseContent, offers: readonly Offer[] = [OFFER]) {
  return render(
    <Showcase
      productName="Plan"
      content={content}
      offers={offers}
      offersLoading={false}
      action={<a href="#prices">See the offers</a>}
    />,
  );
}

describe('a product that has said nothing', () => {
  it('shows its name and its prices rather than an empty frame', () => {
    renderStory(EMPTY);

    // The hero always renders: a product always has a name, and the
    // fallback is the name at display size rather than a blank.
    expect(screen.getByTestId('showcase-headline').textContent).toBe('Plan');
    expect(screen.getByTestId('showcase-pricing')).toBeTruthy();
  });

  it('renders no band it has nothing to put in', () => {
    renderStory(EMPTY);

    expect(screen.queryByTestId('showcase-steps')).toBeNull();
    expect(screen.queryByTestId('showcase-use-cases')).toBeNull();
    expect(screen.queryByTestId('showcase-proof')).toBeNull();
    expect(screen.queryByTestId('showcase-questions')).toBeNull();
  });

  it('offers no menu entry for a band that is not in the document', () => {
    renderStory(EMPTY);

    // Two bands is not a table of contents, so there is no nav at all —
    // and if there were, a link to `#how` would land nowhere.
    expect(screen.queryByTestId('showcase-nav')).toBeNull();
  });
});

describe('a product that has said everything', () => {
  it('renders every band in the order the registry gives them', () => {
    const { container } = renderStory(FULL);

    const order = [...container.querySelectorAll('[data-band]')].map((band) =>
      band.getAttribute('data-band'),
    );

    expect(order).toEqual(ORDERED().map((kind) => BAND_META[kind].anchor));
  });

  it('carries the operator’s words, not the component’s', () => {
    renderStory(FULL);

    expect(screen.getByTestId('showcase-headline').textContent).toBe('Draw a terrace in three minutes');
    expect(screen.getByText('And print the file.')).toBeTruthy();
    expect(screen.getByText('Draw the parcel')).toBeTruthy();
    expect(screen.getByText('A landscaper')).toBeTruthy();
    expect(screen.getByText('The plan, as it prints')).toBeTruthy();
    expect(screen.getByText('Can I cancel?')).toBeTruthy();
  });

  it('lists every band it rendered, and nothing else, in the page menu', () => {
    renderStory(FULL);

    const nav = screen.getByTestId('showcase-nav');
    const targets = [...nav.querySelectorAll('a')].map((link) => link.getAttribute('href'));

    // Every band but the quiet ones: a strip of logos and a closing call to
    // action are on the page and are not places anybody navigates to.
    expect(targets).toEqual(
      bandsInOrder()
        .filter((kind) => (BAND_META[kind] as { quiet?: boolean }).quiet !== true)
        .map((kind) => `#${BAND_META[kind].anchor}`),
    );
    expect(targets).not.toContain('#trust');
    expect(targets).not.toContain('#start');

    // Real links, so they are focusable, middle-clickable and shareable.
    // A list of buttons calling scrollIntoView would be none of those.
    for (const target of targets) {
      expect(target?.startsWith('#')).toBe(true);
    }
  });

  it('points every menu entry at an anchor that exists', () => {
    const { container } = renderStory(FULL);

    const anchors = new Set(
      [...container.querySelectorAll('[data-band]')].map((band) => band.getAttribute('id')),
    );

    for (const link of screen.getByTestId('showcase-nav').querySelectorAll('a')) {
      expect(anchors.has(link.getAttribute('href')?.slice(1) ?? '')).toBe(true);
    }
  });
});

describe('the prices', () => {
  it('come from the catalogue and are never typed into a band', () => {
    renderStory(FULL);

    // 3900 minor units rendered by the shared card, which is the one
    // component that turns a price into words anywhere on this platform.
    expect(within(screen.getByTestId('showcase-pricing')).getByText(/39/)).toBeTruthy();
  });

  it('say the product is no longer sold rather than showing an empty band', () => {
    render(
      <Showcase
        productName="Plan"
        content={EMPTY}
        offers={[]}
        offersLoading={false}
        action={<span />}
        retired
      />,
    );

    const band = screen.getByTestId('showcase-pricing');

    expect(within(band).getByText('No longer sold')).toBeTruthy();
    // Never a Buy that leads to a refusal.
    expect(within(band).queryByRole('button')).toBeNull();
  });
});

describe('the page at rest', () => {
  it('shows every band without waiting for an observer', () => {
    // jsdom has no IntersectionObserver, which is the same position as a
    // browser where the script failed or the reader asked for less motion.
    // The resting state has to be the visible one, or the page shows
    // nothing to a screenshot, a link preview, or anybody scrolling fast.
    const { container } = renderStory(FULL);

    for (const band of container.querySelectorAll('[data-band]')) {
      expect(band.getAttribute('data-revealed')).toBe('true');
    }
  });

  it('does not render a second copy of the call to action while the hero is there', () => {
    renderStory(FULL);

    // Two copies of one action at once is the thing the sticky bar must
    // not do — and with no observer it stays away rather than guessing.
    // Absent rather than hidden: `aria-hidden` over a focusable link is an
    // axe violation, which the accessibility suite caught.
    expect(screen.queryByTestId('showcase-sticky-action')).toBeNull();
  });
});

describe('a bad answer', () => {
  it('renders a page rather than a blank screen', () => {
    // The first thing a stranger sees, with no session behind it to blame.
    // An answer whose `blocks` is missing — an older server, a proxy that
    // rewrote something — has to read as "nothing written yet", which is a
    // page. It used to throw, and took the shell's own frame down with it.
    const { container } = render(
      <Showcase
        productName="Plan"
        content={contentFrom({ product: { code: 'plan', name: 'Plan', active: true } } as never)}
        offers={[]}
        offersLoading={false}
        action={<span />}
      />,
    );

    expect(screen.getByTestId('showcase-headline').textContent).toBe('Plan');
    expect(container.querySelectorAll('[data-band]')).toHaveLength(2);
  });
});

describe('pictures', () => {
  it('shows one on a step and on a use case, described as the operator wrote it', () => {
    renderStory({
      ...FULL,
      steps: [
        pictured('s1', { title: 'Draw the parcel', body: null }, 'The parcel, traced on the map'),
        row('s2', { title: 'The terrace follows', body: null }),
      ],
      useCases: [
        pictured(
          'u1',
          { who: 'A landscaper', before: 'An afternoon of redrawing', after: 'Three minutes' },
          'A landscaper at a laptop',
        ),
      ],
    });

    const steps = within(screen.getByTestId('showcase-steps'));
    const pictures = steps.getAllByTestId('showcase-image');

    // One of the two steps has a picture, so the band draws one frame and
    // not two: a step with nothing to show gets no grey box.
    expect(pictures).toHaveLength(1);
    expect(pictures[0]?.querySelector('img')?.getAttribute('alt')).toBe(
      'The parcel, traced on the map',
    );

    const useCases = within(screen.getByTestId('showcase-use-cases'));
    expect(useCases.getByTestId('showcase-image').querySelector('img')?.getAttribute('alt')).toBe(
      'A landscaper at a laptop',
    );
  });

  it('draws no frame at all where nobody chose a picture', () => {
    renderStory(FULL);

    // Every row in `FULL` has `image: null`, and the bands that could show
    // one show nothing rather than reserving a box for an absence.
    expect(within(screen.getByTestId('showcase-steps')).queryByTestId('showcase-image')).toBeNull();
    expect(
      within(screen.getByTestId('showcase-use-cases')).queryByTestId('showcase-image'),
    ).toBeNull();
  });

  it('leaves a description empty rather than inventing one, which means decorative', () => {
    renderStory({
      ...FULL,
      steps: [pictured('s1', { title: 'Draw the parcel', body: null }, '')],
    });

    // An empty `alt` is a screen reader skipping the image, which is the
    // right answer for one the words beside it already describe. Inventing
    // "a screenshot of the product" would be a sentence that cannot be
    // skipped and says nothing.
    const picture = within(screen.getByTestId('showcase-steps')).getByTestId('showcase-image');

    expect(picture.querySelector('img')?.getAttribute('alt')).toBe('');
  });
});

/**
 * The order of the page, which was `BAND_META[kind].order` — a constant
 * compiled into the bundle — until 2026-09-28.
 */
describe('the order the page is read in', () => {
  // The page as it was before the four bands of 2026-11-20: these cases are
  // about orders written against nine sections, and a band that did not
  // exist then is put back by a rule that has its own case.
  const NINE: ShowcaseContent = { ...FULL, trust: [], stats: [], features: [], cta: [], tour: [], compare: [], video: [], versus: [], hotspots: [] };
  const bandsOn = (container: HTMLElement) =>
    [...container.querySelectorAll('[data-band]')].map((band) => band.getAttribute('data-band'));

  it('follows the product’s order, not the compiled one', () => {
    const { container } = renderStory(NINE);
    const compiled = bandsOn(container);

    const { container: reordered } = render(
      <Showcase
        productName="Plan"
        content={NINE}
        sections={[
          'QUESTION',
          'PRICING',
          'DEMO',
          'PROOF',
          'QUOTE',
          'USE_CASE',
          'STEPS',
          'PROBLEM',
          'HEADLINE',
        ]}
        offers={[OFFER]}
        offersLoading={false}
        action={<span />}
      />,
    );

    expect(bandsOn(reordered)).toEqual([...compiled].reverse());
  });

  it('puts a band the order forgot back in its compiled place', () => {
    // An order stored before `DEMO` and `QUESTION` existed. Each goes back
    // beside the band it follows in the compiled order rather than at the
    // end (2026-09-30) — appending put `DEMO` below the prices on every
    // product that already had an order, which is the one place a band
    // called *Try it* must not be.
    //
    // So `DEMO` follows `PROOF`, and `QUESTION` follows `PRICING` — which
    // this operator moved to the front, taking the questions with it. The
    // rule cannot guess an opinion nobody expressed: they said nothing about
    // a band that did not exist, and the compiled neighbourhood is the only
    // honest answer. Moving it afterwards is what says otherwise.
    const { container } = render(
      <Showcase
        productName="Plan"
        content={NINE}
        sections={['PRICING', 'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF']}
        offers={[OFFER]}
        offersLoading={false}
        action={<span />}
      />,
    );

    expect(bandsOn(container)).toEqual([
      BAND_META.PRICING.anchor,
      BAND_META.QUESTION.anchor,
      BAND_META.HEADLINE.anchor,
      BAND_META.PROBLEM.anchor,
      BAND_META.STEPS.anchor,
      BAND_META.USE_CASE.anchor,
      BAND_META.QUOTE.anchor,
      BAND_META.PROOF.anchor,
      BAND_META.DEMO.anchor,
    ]);
  });

  it('reads the compiled order when the answer carries none', () => {
    // An older server, or a fixture. The page renders as it always did
    // rather than falling back to the database's own order, which is
    // nobody's decision.
    const { container } = renderStory(FULL);

    expect(bandsOn(container)).toEqual(ORDERED().map((kind) => BAND_META[kind].anchor));
  });

  it('takes the nav with it, so the links read in the page’s order', () => {
    const { container } = render(
      <Showcase
        productName="Plan"
        content={NINE}
        sections={[
          'QUESTION',
          'PRICING',
          'DEMO',
          'PROOF',
          'QUOTE',
          'USE_CASE',
          'STEPS',
          'PROBLEM',
          'HEADLINE',
        ]}
        offers={[OFFER]}
        offersLoading={false}
        action={<span />}
      />,
    );

    const targets = [...container.querySelectorAll('nav a')].map((link) => link.getAttribute('href'));

    expect(targets).toEqual(
      ['QUESTION', 'PRICING', 'DEMO', 'PROOF', 'QUOTE', 'USE_CASE', 'STEPS', 'PROBLEM', 'HEADLINE'].map(
        (kind) => `#${BAND_META[kind as keyof typeof BAND_META].anchor}`,
      ),
    );
  });
});

/**
 * The two bands the mockup asked for, and the heading every band can now
 * carry (2026-09-28).
 */
describe('the problem band', () => {
  it('draws the icon it was given, and nothing where the name is unknown', () => {
    renderStory({
      ...FULL,
      problems: [
        row('p1', { title: 'Half a day per job', body: null, icon: 'clock' }),
        row('p2', { title: 'Two figures for one area', body: null, icon: null }),
      ],
    });

    const band = within(screen.getByTestId('showcase-problem'));
    const points = band.getAllByRole('listitem');

    expect(points[0]?.getAttribute('data-icon')).toBe('clock');
    expect(points[0]?.querySelector('svg')).toBeTruthy();

    // No icon is a real answer, not a gap: the sentence is the point.
    expect(points[1]?.getAttribute('data-icon')).toBe('none');
    expect(points[1]?.querySelector('svg')).toBeNull();
  });

  it('hides itself when nobody wrote a problem', () => {
    renderStory({ ...FULL, problems: [] });

    expect(screen.queryByTestId('showcase-problem')).toBeNull();
  });

  // The band's ground is the ink's own colour, and `index.css` paints
  // every heading in the ink — so until 2026-10-08 the band's title and the
  // three points' titles were painted in their own background. The band
  // says once, for every heading it holds, that they take the inverse ink.
  it('writes its headings in the inverse ink, so they can be read on the dark ground', () => {
    renderStory({
      ...FULL,
      headings: { PROBLEM: { eyebrow: null, title: 'Before drawing anything', lede: 'You redraw what exists.' } },
    });

    const band = screen.getByTestId('showcase-problem');
    expect(band.className).toContain('bg-inverse');
    expect(band.querySelector('h2')?.className).toContain('text-on-inverse');
    expect(band.querySelector('h3')?.className).toContain('text-on-inverse');
    // The lede too: `text-muted` is a tone of the ink, not of its inverse.
    expect(screen.getByText('You redraw what exists.').className).toContain('text-on-inverse');
  });

  // On a phone the three points read as one list, each icon beside its
  // title; the three cards are a `md` layout. The classes are the contract.
  it('lays the points out as a divided list under md and three cards from md', () => {
    renderStory(FULL);

    const list = within(screen.getByTestId('showcase-problem')).getByRole('list');
    expect(list.className).toContain('divide-y');
    expect(list.className).toContain('md:grid-cols-3');
  });
});

describe('the quote band', () => {
  it('marks it up as a quotation with its attribution', () => {
    renderStory({
      ...FULL,
      quotes: [row('q1', { quote: 'The quote goes out the same evening.', author: 'A paving contractor' })],
    });

    const band = within(screen.getByTestId('showcase-quotes'));

    // A blockquote and a cite, so a screen reader announces a quotation and
    // who made it — not two paragraphs, one of them smaller and grey.
    expect(band.getByText('The quote goes out the same evening.').closest('blockquote')).toBeTruthy();
    expect(band.getByText('A paving contractor').tagName.toLowerCase()).toBe('cite');
  });

  it('puts nothing in place of an author nobody named', () => {
    renderStory({ ...FULL, quotes: [row('q1', { quote: 'It works.', author: null })] });

    // An invented "— a customer" makes the real testimonials look invented
    // too, so the line is simply absent.
    expect(within(screen.getByTestId('showcase-quotes')).queryByRole('figure')).not.toBeNull();
    expect(screen.queryByText(/a customer/i)).toBeNull();
  });
});

describe('what a band is called', () => {
  it('reads the operator’s words over the ones it was written with', () => {
    renderStory({
      ...FULL,
      headings: {
        STEPS: { eyebrow: 'The tutorial', title: 'Trace, place, print.', lede: 'No training at all.' },
      },
    });

    const band = within(screen.getByTestId('showcase-steps'));

    expect(band.getByText('Trace, place, print.')).toBeTruthy();
    expect(band.getByText('The tutorial')).toBeTruthy();
    expect(band.getByText('No training at all.')).toBeTruthy();
    expect(band.queryByText('How it works')).toBeNull();
  });

  it('falls back field by field, so a missing lede keeps the title', () => {
    renderStory({ ...FULL, headings: { STEPS: { eyebrow: null, title: 'Three moves', lede: null } } });

    const band = within(screen.getByTestId('showcase-steps'));

    expect(band.getByText('Three moves')).toBeTruthy();
    // Nothing invented where the operator wrote nothing.
    expect(screen.queryByTestId('how-eyebrow')).toBeNull();
  });

  it('gives the prices band a heading, which is the only thing it can be given', () => {
    renderStory({
      ...FULL,
      headings: { PRICING: { eyebrow: null, title: 'The first plan costs nothing.', lede: null } },
    });

    // The one section with a heading and no rows: a row for it would be a
    // row somebody could type a price into.
    expect(within(screen.getByTestId('showcase-pricing')).getByText('The first plan costs nothing.')).toBeTruthy();
  });
});
describe('the registry', () => {
  it('describes every band it renders, and renders every band it describes', () => {
    // The promise in docs/home-showcase-spec.md §6: adding a band is four
    // edits. This is the one that fails if somebody makes three of them.
    expect(Object.keys(BAND_VIEWS).sort()).toEqual(Object.keys(BAND_META).sort());
  });

  it('gives every band a unique anchor, because they are ids in one document', () => {
    const anchors = ORDERED().map((kind) => BAND_META[kind].anchor);

    expect(new Set(anchors).size).toBe(anchors.length);
  });
});

/**
 * Four bands that sell (2026-11-20): who trusts it, its numbers, what it
 * includes, and a last call to action.
 */
describe('the bands that sell', () => {
  it('says who trusts it by name, and reads a logo aloud by that name', () => {
    renderStory({
      ...FULL,
      trust: [
        row('t1', { name: 'Atelier Durand' }),
        { id: 't2', content: { name: 'Jardins du Sud' }, image: { url: '/logo.png', alt: '', ratio: 1.6 } },
      ],
    });

    const band = within(screen.getByTestId('showcase-trust'));

    // No logo: the name is the text. A logo: the name is its `alt`, so a
    // screen reader says who it is rather than "image".
    expect(band.getByText('Atelier Durand')).toBeTruthy();
    expect(band.getByAltText('Jardins du Sud').getAttribute('src')).toBe('/logo.png');
  });

  it('shows a figure exactly as it was written, unit included', () => {
    renderStory({
      ...FULL,
      stats: [row('n1', { value: '−40 %', label: 'time spent redrawing', detail: 'Measured on 200 projects.' })],
    });

    const band = within(screen.getByTestId('showcase-stats'));

    // Text, never a number the page computes with or animates up to.
    expect(band.getByText('−40 %').tagName).toBe('DD');
    expect(band.getByText('time spent redrawing')).toBeTruthy();
    expect(band.getByText('Measured on 200 projects.')).toBeTruthy();
  });

  it('closes on the reader’s own call to action, not a second one', () => {
    renderStory({ ...FULL, cta: [row('c1', { title: 'Draw your first terrace', body: 'Free to start.' })] });

    const band = within(screen.getByTestId('showcase-cta'));

    expect(band.getByRole('heading', { name: 'Draw your first terrace' })).toBeTruthy();
    // The action the page was handed (spec §3), which the hero carries too.
    expect(band.getByRole('link', { name: 'See the offers' }).getAttribute('href')).toBe('#prices');
  });

  it('reads all four from the public answer, and drops a figure with nothing beside it', () => {
    const content = contentFrom({
      product: { code: 'plan', name: 'Plan', active: true },
      sections: [],
      bands: {},
      blocks: [
        { id: 'a', block: 'TRUST', position: 10, content: { name: 'Durand' }, image: null },
        { id: 'b', block: 'STATS', position: 10, content: { value: '3 min', label: 'to a plan' }, image: null },
        { id: 'c', block: 'STATS', position: 20, content: { value: '12' }, image: null },
        { id: 'd', block: 'FEATURE', position: 10, content: { title: 'DXF', icon: 'rocket' }, image: null },
        { id: 'e', block: 'CTA', position: 10, content: { title: 'Start' }, image: null },
      ],
    } as never);

    expect(content.trust.map((r) => r.content.name)).toEqual(['Durand']);
    expect(content.stats.map((r) => r.content.value)).toEqual(['3 min']);
    // An icon this bundle cannot draw costs the glyph, never the row.
    expect(content.features[0]?.content).toEqual({ title: 'DXF', body: null, icon: null });
    expect(content.cta[0]?.content.title).toBe('Start');
  });
});

/**
 * A band may be laid out more than one way (2026-11-20). The first layout
 * is the band as it was always drawn, and is what anything unknown means.
 */
describe('how a band is laid out', () => {
  const laidOut = (block: string, layout: string): ShowcaseContent => ({
    ...FULL,
    headings: { [block]: { eyebrow: null, title: null, lede: null, layout } },
  });

  it.each([
    ['HEADLINE', 'showcase-hero', 'split', 'centered'],
    ['STEPS', 'showcase-steps', 'columns', 'timeline'],
    ['FEATURE', 'showcase-features', 'grid', 'list'],
    ['QUOTE', 'showcase-quotes', 'grid', 'single'],
    ['PROOF', 'showcase-proof', 'alternate', 'grid'],
  ])('%s is drawn the usual way unless told otherwise', (block, testId, usual, other) => {
    const find = () => {
      const band = screen.getByTestId(testId);

      return (band.hasAttribute('data-layout') ? band : band.querySelector('[data-layout]'))?.getAttribute('data-layout');
    };

    const first = renderStory(FULL);
    expect(find()).toBe(usual);
    first.unmount();

    const second = renderStory(laidOut(block, other));
    expect(find()).toBe(other);
    second.unmount();

    // A layout stored by a newer deployment costs the usual one, never a
    // blank band.
    renderStory(laidOut(block, 'holographic'));
    expect(find()).toBe(usual);
  });

  it('keeps a band’s own words when only its layout was chosen', () => {
    renderStory(laidOut('STEPS', 'timeline'));

    expect(within(screen.getByTestId('showcase-steps')).getByRole('heading', { name: 'How it works' })).toBeTruthy();
  });

  it('reads the layout from the public answer, alone or beside a title', () => {
    const content = contentFrom({
      product: { code: 'plan', name: 'Plan', active: true },
      sections: [],
      bands: { HEADLINE: { layout: 'centered' }, STEPS: { title: 'Three moves', layout: 'timeline' } },
      blocks: [],
    } as never);

    expect(content.headings.HEADLINE).toEqual({ eyebrow: null, title: null, lede: null, layout: 'centered', menu: null, columns: null });
    expect(content.headings.STEPS?.layout).toBe('timeline');
  });

  it('leaves its menu out where the page asks, and draws it otherwise', () => {
    // FULL has more than three bands to name, so the bar is drawn.
    const { unmount } = renderStory(FULL);

    expect(screen.getByTestId('showcase-nav')).toBeTruthy();
    unmount();

    renderStory({ ...FULL, headings: { HEADLINE: { eyebrow: null, title: null, lede: null, menu: 'hidden' } } });

    expect(screen.queryByTestId('showcase-nav')).toBeNull();
    // The page itself is untouched: only its table of contents went.
    expect(screen.getByTestId('showcase-steps')).toBeTruthy();
  });

  it('draws the menu for a code it does not know, rather than hiding the page’s way around', () => {
    renderStory({ ...FULL, headings: { HEADLINE: { eyebrow: null, title: null, lede: null, menu: 'tabs' } } });

    expect(screen.getByTestId('showcase-nav')).toBeTruthy();
  });
});

/**
 * The proof band's third layout (2026-11-21): the screenshots large, one at
 * a time, and moved only by the reader.
 */
describe('the proof carousel', () => {
  const three: ShowcaseContent = {
    ...FULL,
    proof: [
      row('p1', { caption: 'The plot, as it arrives' }),
      row('p2', { caption: 'The terrace, placed' }),
      row('p3', { caption: 'The plan, as it prints' }),
    ],
    headings: { PROOF: { eyebrow: null, title: 'See it work', lede: null, layout: 'carousel' } },
  };

  it('keeps every picture in the document, so nothing is reachable by button only', () => {
    renderStory(three);

    const carousel = within(screen.getByTestId('proof-carousel'));

    // All three captions at once: the row scrolls, no slide is hidden or
    // mounted late, so a screen reader and a swipe reach each of them.
    expect(carousel.getByText('The plot, as it arrives')).toBeTruthy();
    expect(carousel.getByText('The terrace, placed')).toBeTruthy();
    expect(carousel.getByText('The plan, as it prints')).toBeTruthy();
    expect(screen.getByTestId('proof-carousel').getAttribute('aria-label')).toBe('See it work');
  });

  it('says where the reader is in words, and stops at both ends', () => {
    renderStory(three);

    const previous = screen.getByTestId<HTMLButtonElement>('carousel-previous');
    const next = screen.getByTestId<HTMLButtonElement>('carousel-next');
    const position = screen.getByTestId('carousel-position');

    expect(position.textContent).toBe('1 of 3');
    expect(position.getAttribute('aria-live')).toBe('polite');
    expect(previous.disabled).toBe(true);

    fireEvent.click(next);
    fireEvent.click(next);

    expect(position.textContent).toBe('3 of 3');
    expect(next.disabled).toBe(true);
    expect(previous.disabled).toBe(false);
  });

  it('offers no buttons for one picture, which has nowhere to go', () => {
    renderStory({ ...three, proof: [row('p1', { caption: 'The plot, as it arrives' })] });

    expect(screen.getByTestId('proof-carousel')).toBeTruthy();
    expect(screen.queryByTestId('carousel-next')).toBeNull();
  });
});

/**
 * What the product includes, shown one screenshot at a time (2026-11-21).
 */
describe('the tour', () => {
  const tour: ShowcaseContent = {
    ...FULL,
    tour: [
      row('o1', { title: 'The cadastral plot', body: 'With its real boundaries.' }),
      row('o2', { title: 'The relief', body: 'The slope nobody drew.' }),
      row('o3', { title: 'The terrace', body: null }),
    ],
  };

  it('is a tablist a screen reader can announce, with one tab in the tab order', () => {
    renderStory(tour);

    const band = within(screen.getByTestId('showcase-tour'));
    const tabs = band.getAllByRole('tab');

    expect(band.getByRole('tablist').getAttribute('aria-orientation')).toBe('vertical');
    expect(tabs.map((tab) => tab.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
    expect(tabs.map((tab) => tab.getAttribute('tabindex'))).toEqual(['0', '-1', '-1']);
    // Each tab names the panel it controls, and the panel names its tab.
    const panel = document.getElementById(tabs[0]?.getAttribute('aria-controls') ?? '');
    expect(panel?.getAttribute('aria-labelledby')).toBe(tabs[0]?.id);
  });

  it('keeps every panel in the document and shows the one chosen', () => {
    renderStory(tour);

    const band = screen.getByTestId('showcase-tour');
    const hidden = () => [...band.querySelectorAll('[role="tabpanel"]')].map((panel) => (panel as HTMLElement).hidden);

    expect(hidden()).toEqual([false, true, true]);
    // There, though not shown: a search engine and find-in-page read it.
    expect(band.textContent).toContain('The slope nobody drew.');

    fireEvent.click(within(band).getByRole('tab', { name: 'The relief' }));

    expect(hidden()).toEqual([true, false, true]);
  });

  it('moves between tabs with the arrow keys, and wraps', () => {
    renderStory(tour);

    const band = within(screen.getByTestId('showcase-tour'));
    const selected = () => band.getAllByRole('tab').findIndex((tab) => tab.getAttribute('aria-selected') === 'true');

    fireEvent.keyDown(band.getByRole('tablist'), { key: 'ArrowDown' });
    expect(selected()).toBe(1);
    expect(document.activeElement).toBe(band.getAllByRole('tab')[1]);

    fireEvent.keyDown(band.getByRole('tablist'), { key: 'End' });
    expect(selected()).toBe(2);

    fireEvent.keyDown(band.getByRole('tablist'), { key: 'ArrowDown' });
    expect(selected()).toBe(0);
  });
});

/**
 * One plot twice (2026-11-21): two pictures under a handle, or two embedded
 * pages behind a switch.
 */
describe('before and after', () => {
  const picture = (url: string) => ({ url, alt: '', ratio: 1.6 });
  const side = (id: string, label: string, embedUrl: string | null, url: string | null) => ({
    id,
    content: { label, caption: null, embedUrl, ratio: null },
    image: url === null ? null : picture(url),
  });

  it('draws nothing for one side, which is not a comparison', () => {
    renderStory({ ...FULL, compare: [side('k1', 'Before', null, '/before.webp')] });

    expect(screen.queryByTestId('showcase-compare')).toBeNull();
    // And is not in the page menu either: no link to a band that is not there.
    expect(screen.getByTestId('showcase-nav').querySelector('[href="#compare"]')).toBeNull();
  });

  it('compares two pictures with a handle that is a real slider', () => {
    renderStory({
      ...FULL,
      compare: [side('k1', 'Before', null, '/before.webp'), side('k2', 'After', null, '/after.webp')],
    });

    const band = within(screen.getByTestId('showcase-compare'));
    const handle = band.getByTestId<HTMLInputElement>('compare-handle');

    // A range input: a mouse, a finger and the arrow keys all move it, and
    // it is announced as a slider with a value.
    expect(handle.type).toBe('range');
    expect(handle.getAttribute('aria-label')).toContain('Before');
    expect(band.getByTestId('compare-before').style.clipPath).toBe('inset(0 50% 0 0)');

    fireEvent.change(handle, { target: { value: '80' } });

    expect(band.getByTestId('compare-before').style.clipPath).toBe('inset(0 20% 0 0)');
    // A picture nobody described is read by the name of its side.
    expect(band.getByAltText('After')).toBeTruthy();
  });

  it('switches between two embedded pages, both started together so they stay in step', () => {
    renderStory({
      ...FULL,
      compare: [
        side('k1', 'Before', 'https://plan.example.test/?file=1&x={width}', null),
        side('k2', 'After', 'https://plan.example.test/?file=2&x={width}', null),
      ],
    });

    const band = screen.getByTestId('showcase-compare');
    const frames = () => [...band.querySelectorAll('iframe')].map((frame) => frame.getAttribute('src'));
    const shown = (index: number) => band.querySelector<HTMLElement>(`[data-compare-side="${String(index)}"]`);

    // No handle: two live 3D views cannot be overlaid.
    expect(within(band).queryByTestId('compare-handle')).toBeNull();

    // Both from the start (2026-11-22). Loaded one after the other, two
    // views that turn by themselves were never at the same moment, and
    // switching compared two angles rather than two gardens.
    expect(frames()).toHaveLength(2);
    expect(frames()[0]).toContain('file=1');
    expect(frames()[1]).toContain('file=2');

    // The one not shown is out of reach, not out of the layout: a frame
    // the browser does not draw stops animating and falls behind.
    expect(shown(0)?.getAttribute('data-shown')).toBe('true');
    expect(shown(1)?.getAttribute('data-shown')).toBe('false');
    expect(shown(1)?.hasAttribute('inert')).toBe(true);
    expect(shown(1)?.hidden).toBe(false);

    fireEvent.click(within(band).getByTestId('compare-show-1'));

    expect(within(band).getByTestId('compare-show-1').getAttribute('aria-pressed')).toBe('true');
    expect(shown(0)?.hasAttribute('inert')).toBe(true);
    expect(shown(1)?.hasAttribute('inert')).toBe(false);
    // The same two frames: switching reloaded nothing.
    expect(frames()).toHaveLength(2);
  });

  it('draws both sides next to each other where the band asks for it', () => {
    renderStory({
      ...FULL,
      headings: { COMPARE: { eyebrow: null, title: null, lede: null, layout: 'beside' } },
      compare: [
        side('k1', 'Before', 'https://plan.example.test/?file=1&x={width}', null),
        side('k2', 'After', 'https://plan.example.test/?file=2&x={width}', null),
      ],
    });

    const band = screen.getByTestId('showcase-compare');

    expect(band.querySelector('[data-compare="beside"]')).toBeTruthy();
    expect(band.querySelectorAll('iframe')).toHaveLength(2);
    // Nothing to switch: both are in front of the reader.
    expect(within(band).queryByTestId('compare-show-0')).toBeNull();
    expect(band.querySelector('[inert]')).toBeNull();
  });

  it('reads both sides from the public answer, in their order', () => {
    const content = contentFrom({
      product: { code: 'plan', name: 'Plan', active: true },
      sections: [],
      bands: {},
      blocks: [
        { id: 'b', block: 'COMPARE', position: 20, content: { label: 'After', embed_url: 'https://plan.example.test/?file=2' }, image: null },
        { id: 'a', block: 'COMPARE', position: 10, content: { label: 'Before', ratio: '4:3' }, image: '/before.webp' },
        { id: 't', block: 'TOUR', position: 10, content: { title: 'The plot' }, image: null },
      ],
    } as never);

    expect(content.compare.map((r) => r.content.label)).toEqual(['Before', 'After']);
    expect(content.compare[0]?.content.ratio).toBe('4:3');
    expect(content.compare[1]?.content.embedUrl).toBe('https://plan.example.test/?file=2');
    expect(content.tour[0]?.content).toEqual({ title: 'The plot', body: null });
  });
});

/**
 * The widgets of 2026-11-23: four bands and four layouts. What is claimed
 * is what each does that the page did not — and, for the ones that read
 * the outside world (a film, the catalogue), that they read only it.
 */
describe('a line of news', () => {
  it('is drawn above the page’s menu and its headline, whatever its place in the order', () => {
    renderStory({ ...FULL, announce: [row('n', { text: 'New: the sun at any hour', linkLabel: 'See the prices', linkUrl: '#prices' })] });

    const page = screen.getByTestId('showcase');
    const news = screen.getByTestId('showcase-announce');

    expect(page.firstElementChild).toBe(news);
    expect(news.textContent).toContain('New: the sun at any hour');
    // On the page: no new tab for a place on it.
    expect(within(news).getByRole('link').getAttribute('target')).toBeNull();
  });

  it('opens a link that leaves the page beside it', () => {
    renderStory({ ...FULL, announce: [row('n', { text: 'Read the story', linkLabel: null, linkUrl: 'https://example.test/story' })] });

    const link = within(screen.getByTestId('showcase-announce')).getByRole('link');

    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
  });
});

describe('a film', () => {
  it('loads nothing from the player until it is played, then plays it from its no-cookie origin', () => {
    renderStory({ ...FULL, video: [row('v', { caption: 'Two minutes of Plan', videoUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' })] });

    const band = screen.getByTestId('showcase-video');

    expect(band.querySelector('iframe')).toBeNull();

    fireEvent.click(within(band).getByTestId('video-play'));

    expect(within(band).getByTestId('video-player').getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0',
    );
  });

  it.each([
    ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'],
    ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'],
    ['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871?autoplay=1&dnt=1'],
    ['https://elsewhere.test/film.mp4', null],
  ])('plays %s from the player’s own address', (address, player) => {
    expect(playerOf(address)).toBe(player);
  });
});

describe('a comparison table', () => {
  it('names the product’s own column, the others from the band, and says each mark in words', () => {
    renderStory({
      ...FULL,
      headings: { VERSUS: { eyebrow: null, title: null, lede: null, columns: 'An architect; A spreadsheet' } },
      versus: [row('c', { criterion: 'Your plot in 3D', note: null, ours: 'yes', others: ['partly', 'no', null] })],
    });

    const table = within(screen.getByTestId('showcase-versus'));
    const headers = table.getAllByRole('columnheader').map((cell) => cell.textContent);

    expect(headers.slice(1)).toEqual(['Plan', 'An architect', 'A spreadsheet']);
    expect(table.getByRole('row', { name: /Your plot in 3D/ }).textContent).toContain('Yes');
    // Two other columns named, so the third verdict is not drawn.
    expect(table.getAllByRole('cell')).toHaveLength(3);
  });
});

describe('points on a picture', () => {
  const pictured = { url: '/plot.webp', alt: 'The plot', ratio: 1.6 };

  it('puts each point where it was placed, and says what the chosen one is', () => {
    renderStory({
      ...FULL,
      hotspots: [
        { id: 'p1', content: { title: 'The terrace', body: 'Drawn to scale.', x: 20, y: 30 }, image: pictured },
        { id: 'p2', content: { title: 'The pool', body: null, x: 70, y: 60 }, image: null },
      ],
    });

    const band = screen.getByTestId('showcase-hotspot');
    const second = band.querySelector<HTMLElement>('[data-hotspot="p2"]')!;

    expect(second.style.left).toBe('70%');
    expect(second.style.top).toBe('60%');
    expect(within(band).getByTestId('hotspot-said').textContent).toContain('The terrace');

    fireEvent.click(second);

    expect(within(band).getByTestId('hotspot-said').textContent).toContain('The pool');
  });

  it('is not drawn without the first point’s picture: there is nothing to put the points on', () => {
    renderStory({ ...FULL, hotspots: [row('p1', { title: 'The terrace', body: null, x: 20, y: 30 })] });

    expect(screen.queryByTestId('showcase-hotspot')).toBeNull();
  });
});

describe('the new layouts', () => {
  it('counts a figure up in words written the operator’s way, and says the figure itself', () => {
    renderStory({
      ...FULL,
      headings: { STATS: { eyebrow: null, title: null, lede: null, layout: 'counter' } },
      stats: [row('s', { value: '1 200 projets', label: 'Drawn', detail: null })],
    });

    const figure = screen.getByTestId('showcase-stats').querySelector('[data-count]')!;

    // No IntersectionObserver here, which is a reader who sees it at once.
    expect(figure.textContent).toContain('1 200 projets');
  });

  it('passes the logos by, the second copy hidden from a screen reader', () => {
    renderStory({ ...FULL, headings: { TRUST: { eyebrow: null, title: null, lede: null, layout: 'marquee' } } });

    const band = screen.getByTestId('showcase-trust');
    const lists = band.querySelectorAll('ul');

    expect(band.querySelector('[data-layout="marquee"]')).toBeTruthy();
    expect(lists).toHaveLength(2);
    expect(lists[1]?.getAttribute('aria-hidden')).toBe('true');
  });

  it('opens a screenshot of the gallery large, and closes it on Escape', () => {
    const shot = { url: '/a.webp', alt: 'The plot', ratio: 1.6 };

    renderStory({
      ...FULL,
      headings: { PROOF: { eyebrow: null, title: null, lede: null, layout: 'gallery' } },
      proof: [
        { id: 'g1', content: { caption: 'The plot' }, image: shot },
        { id: 'g2', content: { caption: 'The terrace' }, image: { ...shot, url: '/b.webp' } },
      ],
    });

    fireEvent.click(within(screen.getByTestId('showcase-proof')).getByText('The terrace'));
    expect(screen.getByTestId('proof-lightbox').getAttribute('aria-label')).toBe('The terrace');

    fireEvent.keyDown(document, { key: 'ArrowRight' });
    expect(screen.getByTestId('proof-lightbox').getAttribute('aria-label')).toBe('The plot');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByTestId('proof-lightbox')).toBeNull();
  });
});

describe('the offers, side by side', () => {
  const grant = (feature: string, name: string, extra: Partial<{ kind: 'BOOLEAN' | 'QUOTA'; limit: number | null; unlimited: boolean; unit: string | null }> = {}) => ({
    feature,
    name,
    kind: 'BOOLEAN' as const,
    unit: null,
    limit: null,
    unlimited: false,
    ...extra,
  });
  const offers = [
    { ...OFFER, id: 'o1', name: 'Solo', version: { ...OFFER.version, grants: [grant('3d', 'Your plot in 3D'), grant('projects', 'Projects', { kind: 'QUOTA', limit: 5, unit: 'projects' })] } },
    { ...OFFER, id: 'o2', name: 'Pro', version: { ...OFFER.version, grants: [grant('3d', 'Your plot in 3D'), grant('projects', 'Projects', { kind: 'QUOTA', unlimited: true }), grant('pdf', 'PDF export')] } },
  ];

  it('lays out what each offer grants under the cards, from the catalogue and nothing else', () => {
    renderStory({ ...FULL, headings: { PRICING: { eyebrow: null, title: null, lede: null, layout: 'table' } } }, offers);

    const matrix = within(screen.getByTestId('offer-matrix'));

    expect(matrix.getAllByRole('row').map((line) => line.getAttribute('data-feature'))).toEqual([null, '3d', 'projects', 'pdf']);
    expect(matrix.getByRole('row', { name: /Projects/ }).textContent).toContain('5projects');
    expect(matrix.getByRole('row', { name: /Projects/ }).textContent).toContain('Unlimited');
    // Solo does not grant the export: a dash, said as such.
    expect(matrix.getByRole('row', { name: /PDF export/ }).textContent).toContain('Not included');
  });

  it('draws no table under cards alone', () => {
    renderStory(FULL, offers);

    expect(screen.queryByTestId('offer-matrix')).toBeNull();
  });
});

