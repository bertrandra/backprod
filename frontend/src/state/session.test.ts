import { beforeEach, describe, expect, it } from 'vitest';

import { rememberedProduct, useSessionStore } from './session';

/**
 * The product has to survive a reload, and U5 is where that stopped being
 * theoretical.
 *
 * `?product=` seeds the first visit, but the router validates search params and
 * drops anything it does not know — so after one in-app navigation the parameter
 * is gone. Before this, a reload landed on "No product selected" with everything
 * working and nothing visible.
 */
beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: null });
});

describe('choosing a product', () => {
  it('is remembered for the next page load', () => {
    useSessionStore.getState().chooseProduct('atlas');

    expect(rememberedProduct()).toBe('atlas');
  });

  it('is forgotten on sign-out', () => {
    // A token change is a different person. Leaving the product behind would put
    // the next one inside a product they may not have.
    useSessionStore.getState().chooseProduct('atlas');
    useSessionStore.getState().signOut();

    expect(rememberedProduct()).toBeNull();
    expect(useSessionStore.getState().productCode).toBeNull();
  });

  it('answers null when nothing was ever chosen', () => {
    expect(rememberedProduct()).toBeNull();
  });

  it('treats an empty stored value as nothing', () => {
    window.localStorage.setItem('backprod.product', '');

    expect(rememberedProduct()).toBeNull();
  });
});

describe('when storage is unavailable', () => {
  it('does not stop the application from choosing a product', () => {
    // A private window, cleared site data, or a browser set to block storage.
    // The product still works for this page's lifetime; it just will not outlive
    // a reload.
    const original = window.localStorage.setItem.bind(window.localStorage);

    window.localStorage.setItem = () => {
      throw new Error('storage is blocked');
    };

    expect(() => useSessionStore.getState().chooseProduct('atlas')).not.toThrow();
    expect(useSessionStore.getState().productCode).toBe('atlas');

    window.localStorage.setItem = original;
  });

  it('reads as nothing rather than throwing', () => {
    const original = window.localStorage.getItem.bind(window.localStorage);

    window.localStorage.getItem = () => {
      throw new Error('storage is blocked');
    };

    expect(rememberedProduct()).toBeNull();

    window.localStorage.getItem = original;
  });
});
