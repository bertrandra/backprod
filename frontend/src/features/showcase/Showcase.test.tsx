import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Showcase } from './Showcase';
import type { ShowcaseContent } from './blocks/content';
import { contentFrom } from './blocks/fromApi';
import { BAND_META, bandsInOrder } from './blocks/meta';
import { BAND_VIEWS } from './blocks/registry';

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
};

const EMPTY: ShowcaseContent = {
  headline: [],
  problems: [],
  steps: [],
  useCases: [],
  quotes: [],
  proof: [],
  demos: [],
  questions: [],
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

function renderStory(content: ShowcaseContent, offers = [OFFER]) {
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

    expect(order).toEqual(bandsInOrder().map((kind) => BAND_META[kind].anchor));
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

    expect(targets).toEqual(bandsInOrder().map((kind) => `#${BAND_META[kind].anchor}`));

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
  const bandsOn = (container: HTMLElement) =>
    [...container.querySelectorAll('[data-band]')].map((band) => band.getAttribute('data-band'));

  it('follows the product’s order, not the compiled one', () => {
    const { container } = renderStory(FULL);
    const compiled = bandsOn(container);

    const { container: reordered } = render(
      <Showcase
        productName="Plan"
        content={FULL}
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
    // An order stored before `DEMO` and `QUESTION` existed. They are
    // appended rather than dropped: the alternative is a band invisible on
    // every product until somebody re-saves each one by hand.
    const { container } = render(
      <Showcase
        productName="Plan"
        content={FULL}
        sections={['PRICING', 'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF']}
        offers={[OFFER]}
        offersLoading={false}
        action={<span />}
      />,
    );

    expect(bandsOn(container)).toEqual([
      BAND_META.PRICING.anchor,
      BAND_META.HEADLINE.anchor,
      BAND_META.PROBLEM.anchor,
      BAND_META.STEPS.anchor,
      BAND_META.USE_CASE.anchor,
      BAND_META.QUOTE.anchor,
      BAND_META.PROOF.anchor,
      BAND_META.DEMO.anchor,
      BAND_META.QUESTION.anchor,
    ]);
  });

  it('reads the compiled order when the answer carries none', () => {
    // An older server, or a fixture. The page renders as it always did
    // rather than falling back to the database's own order, which is
    // nobody's decision.
    const { container } = renderStory(FULL);

    expect(bandsOn(container)).toEqual(bandsInOrder().map((kind) => BAND_META[kind].anchor));
  });

  it('takes the nav with it, so the links read in the page’s order', () => {
    const { container } = render(
      <Showcase
        productName="Plan"
        content={FULL}
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
    const anchors = bandsInOrder().map((kind) => BAND_META[kind].anchor);

    expect(new Set(anchors).size).toBe(anchors.length);
  });
});
