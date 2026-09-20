import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useOffers } from '@/queries/catalogue';
import { useSessionStore } from '@/state/session';
import { recordingClient, renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProductSwitcher } from './ProductSwitcher';

/** A screen beside the switcher whose query key does not name the product. */
function Offers() {
  const offers = useOffers();

  return <span data-testid="offers">{offers.data?.length ?? '…'}</span>;
}

/**
 * Switching product, and the reason it clears the cache.
 *
 * Every cached answer was scoped to the previous product — `X-Product` is on
 * every request — so keeping any of it would show one product's data under
 * another's name. The test below asserts the *consequence*: after a switch, a
 * screen's data is asked for again rather than served from what was already
 * there.
 */
const ATLAS = { id: 'prod-atlas', code: 'atlas', name: 'Atlas' };
const BOREAS = { id: 'prod-boreas', code: 'boreas', name: 'Boreas' };

function clientFor(products: unknown[], extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION },
    'GET /api/v1/products': { data: { products } },
    ...extra,
  });
}

describe('with one product', () => {
  it('names it and offers no control', async () => {
    renderWith(<ProductSwitcher />, clientFor([ATLAS]));

    // Waited on the *name*, not on the element: the element is there from the
    // first render carrying the code, so waiting for it would prove nothing about
    // the list having arrived.
    await waitFor(() => expect(screen.getByTestId('active-product').textContent).toBe('Atlas'));
    // A menu with one option is a menu that does nothing.
    expect(screen.queryByTestId('product-switcher')).toBeNull();
  });

  it('shows the code before the list has arrived', async () => {
    // The code is what every request is already carrying, so it is the honest
    // thing to show while the names are still loading.
    renderWith(<ProductSwitcher />, clientFor([]));

    await waitFor(() =>
      expect(screen.getByTestId('active-product').getAttribute('data-product')).toBe('atlas'),
    );
  });
});

describe('with more than one', () => {
  it('offers them all, with the current one selected', async () => {
    renderWith(<ProductSwitcher />, clientFor([ATLAS, BOREAS]));

    const select = await waitFor(() =>
      screen.getByTestId<HTMLSelectElement>('product-switcher'),
    );

    expect(select.value).toBe('atlas');
    expect([...select.options].map((option) => option.value)).toEqual(['atlas', 'boreas']);
  });

  it('changes the product every later request carries', async () => {
    renderWith(<ProductSwitcher />, clientFor([ATLAS, BOREAS]));

    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());

    fireEvent.change(screen.getByTestId('product-switcher'), { target: { value: 'boreas' } });

    expect(useSessionStore.getState().productCode).toBe('boreas');
  });

  it('drops everything the previous product answered', async () => {
    let productReads = 0;

    renderWith(
      <ProductSwitcher />,
      clientFor([ATLAS, BOREAS], {
        'GET /api/v1/products': (): Stub => {
          productReads += 1;

          return { data: { products: [ATLAS, BOREAS] } };
        },
      }),
    );

    // The request count rises when the request is *made*; the control appears
    // when the answer has been applied. Waiting on the control is what makes the
    // interaction below possible.
    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());
    expect(productReads).toBe(1);

    fireEvent.change(screen.getByTestId('product-switcher'), { target: { value: 'boreas' } });

    // The cache was cleared, so even this component's own list is asked again.
    // Anything else on screen — projects, quotes, invoices — is asked again too,
    // which is the whole point: none of it was about Boreas.
    await waitFor(() => expect(productReads).toBeGreaterThan(1));
  });

  it('makes a screen whose key never named the product ask again', async () => {
    // The catalogue's offers are keyed without the product — the product is
    // on the request, not in the key — so emptying the cache alone left the
    // mounted query showing the old product's offers until something else
    // woke it (2026-09-18). The switch must make it *ask*, not merely forget.
    let offerReads = 0;

    renderWith(
      <>
        <ProductSwitcher />
        <Offers />
      </>,
      clientFor([ATLAS, BOREAS], {
        'GET /api/v1/offers': (): Stub => {
          offerReads += 1;

          return { data: { offers: [] } };
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());
    await waitFor(() => expect(offerReads).toBe(1));

    fireEvent.change(screen.getByTestId('product-switcher'), { target: { value: 'boreas' } });

    await waitFor(() => expect(offerReads).toBe(2));
  });

  it('leaves for a product that lives beside the platform, with the code alone', async () => {
    // ADR-051 §3: a full navigation to its address, `?product=` and the
    // language appended, no token — the cookie signs them in there.
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    const PLAN = { id: 'prod-plan', code: 'plan', name: 'Plan', app_url: 'https://plan.example.test' };

    renderWith(<ProductSwitcher />, clientFor([ATLAS, PLAN]));

    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());
    fireEvent.change(screen.getByTestId('product-switcher'), { target: { value: 'plan' } });

    expect(assign).toHaveBeenCalledWith('https://plan.example.test/?product=plan&lang=en');
    // The store did not move: this shell is still on its own product.
    expect(useSessionStore.getState().productCode).toBe('atlas');
  });

  it('does nothing when the same product is chosen again', async () => {
    let productReads = 0;

    renderWith(
      <ProductSwitcher />,
      clientFor([ATLAS, BOREAS], {
        'GET /api/v1/products': (): Stub => {
          productReads += 1;

          return { data: { products: [ATLAS, BOREAS] } };
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());
    expect(productReads).toBe(1);

    fireEvent.change(screen.getByTestId('product-switcher'), { target: { value: 'atlas' } });

    // Clearing the cache to arrive where you already were is a page that blinks
    // for no reason.
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(productReads).toBe(1);
  });
});

/**
 * On the console, the list is the platform's own (ADR-047).
 *
 * A platform role grants no membership, so `listProducts` would answer a
 * platform administrator about their own tenant or not at all. The switcher
 * asks `listPlatformProducts` instead — retired products included, because
 * they still carry tenants and invoices — and never the membership list.
 */
describe('with nothing chosen yet', () => {
  it("chooses the person's default product where the server names one", async () => {
    // The product signed up for, or the one chosen on the profile: where
    // this person's screens open when the address names none.
    renderWith(
      <ProductSwitcher />,
      stubClient({
        'GET /api/v1/me': { data: SESSION },
        'GET /api/v1/products': { data: { products: [ATLAS, BOREAS], default: 'boreas' } },
      }),
      { product: null },
    );

    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('boreas'));
  });

  it('chooses the first product this person has, so a sign-in at the landing address is not a blank screen', async () => {
    // Signing out forgets the product on purpose; signing in again at "/"
    // has no `?product=` to seed from. Before 2026-09-17 nothing chose,
    // and no product meant no `/me`, no menu, nothing on top.
    renderWith(<ProductSwitcher />, clientFor([BOREAS, ATLAS]), { product: null });

    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('boreas'));
    expect(screen.getByTestId<HTMLSelectElement>('product-switcher').value).toBe('boreas');
  });
});

describe('on the console', () => {
  const PLATFORM_ATLAS = { id: 'prod-atlas', code: 'atlas', name: 'Atlas', active: true };
  const PLATFORM_BOREAS = { id: 'prod-boreas', code: 'boreas', name: 'Boreas', active: true };
  const PLATFORM_COMET = { id: 'prod-comet', code: 'comet', name: 'Comet', active: false };

  it('lists every product the platform hosts, and marks the retired ones', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [PLATFORM_ATLAS, PLATFORM_BOREAS, PLATFORM_COMET] } },
      'GET /api/v1/products': { data: { products: [ATLAS] } },
    });

    renderWith(<ProductSwitcher platform />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('product-switcher'));

    expect([...select.options].map((option) => option.value)).toEqual(['atlas', 'boreas', 'comet']);
    expect([...select.options].map((option) => option.textContent)).toEqual(['Atlas', 'Boreas', 'Comet (retired)']);
    expect(select.options[2]?.getAttribute('data-retired')).toBe('true');
    // Never the membership list.
    expect(requests.some((r) => r.path === '/api/v1/products')).toBe(false);
  });

  it('chooses the first active product when nothing is chosen yet', async () => {
    renderWith(
      <ProductSwitcher platform />,
      stubClient({
        'GET /api/v1/staff/products': { data: { products: [PLATFORM_COMET, PLATFORM_BOREAS] } },
      }),
      { product: null },
    );

    // Comet is retired and listed first; the choice made for them is the
    // first product somebody can still act in.
    await waitFor(() => expect(useSessionStore.getState().productCode).toBe('boreas'));
    expect(screen.getByTestId<HTMLSelectElement>('product-switcher').value).toBe('boreas');
  });

  it('never asks the platform list in the application', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/products': { data: { products: [ATLAS, BOREAS] } },
      'GET /api/v1/staff/products': { data: { products: [PLATFORM_ATLAS] } },
    });

    renderWith(<ProductSwitcher />, client);

    await waitFor(() => expect(screen.getByTestId('product-switcher')).toBeTruthy());

    expect(requests.some((r) => r.path === '/api/v1/staff/products')).toBe(false);
  });
});
