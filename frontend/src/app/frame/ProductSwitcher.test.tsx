import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProductSwitcher } from './ProductSwitcher';

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
