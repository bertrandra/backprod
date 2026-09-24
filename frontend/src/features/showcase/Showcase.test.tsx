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
    grants: [],
  },
};

const row = <T,>(id: string, content: T) => ({ id, content, image: null });

const FULL: ShowcaseContent = {
  headline: [row('h', { headline: 'Draw a terrace in three minutes', subline: 'And print the file.' })],
  steps: [row('s1', { title: 'Draw the parcel', body: null }), row('s2', { title: 'The terrace follows', body: null })],
  useCases: [row('u1', { who: 'A landscaper', before: 'An afternoon of redrawing', after: 'Three minutes' })],
  proof: [row('p1', { caption: 'The plan, as it prints' })],
  questions: [row('q1', { question: 'Can I cancel?', answer: 'Yes, at the end of the period.' })],
};

const EMPTY: ShowcaseContent = { headline: [], steps: [], useCases: [], proof: [], questions: [] };

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
  it('renders the six bands in the order the registry gives them', () => {
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
