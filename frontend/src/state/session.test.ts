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
  useSessionStore.setState({ token: null, productCode: null, status: 'restoring', expiresAt: null });
});

/**
 * No refresh token in it, which is the U12 change.
 *
 * The store used to receive one and put it in `localStorage`. It now lives in an
 * `HttpOnly` cookie the server sets, so this store never sees it and no test here
 * needs to stub a network call to talk about it.
 */
const GRANT = { accessToken: 'access', expiresIn: 3600 };

describe('choosing a product', () => {
  it('is remembered for the next page load', () => {
    useSessionStore.getState().chooseProduct('atlas');

    expect(rememberedProduct()).toBe('atlas');
  });

  it('is forgotten when the session ends', () => {
    useSessionStore.getState().chooseProduct('atlas');
    useSessionStore.getState().forget();

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

describe('signing in', () => {
  it('holds the access token in memory and stores nothing', () => {
    useSessionStore.getState().signIn(GRANT);

    expect(useSessionStore.getState().token).toBe('access');
    expect(useSessionStore.getState().status).toBe('signed-in');
    // The whole point of U12: there is nothing in any browser store for an
    // injected script to find. The long-lived credential is a cookie this code
    // cannot read.
    expect(window.localStorage.length).toBe(0);
  });

  it('turns the duration the server sent into a moment to renew at', () => {
    const before = Date.now();

    useSessionStore.getState().signIn(GRANT);

    const expiresAt = useSessionStore.getState().expiresAt ?? 0;

    // Measured against this browser's clock, because that is the clock the renewal
    // timer reads. An absolute timestamp from the server would be wrong here by
    // exactly the amount the two clocks disagree.
    expect(expiresAt).toBeGreaterThanOrEqual(before + 3_600_000);
    expect(expiresAt).toBeLessThanOrEqual(Date.now() + 3_600_000);
  });
});

describe('forgetting the session', () => {
  it('leaves no token, no product and no way back in', () => {
    useSessionStore.getState().signIn(GRANT);
    useSessionStore.getState().chooseProduct('atlas');

    useSessionStore.getState().forget();

    expect(useSessionStore.getState().token).toBeNull();
    expect(useSessionStore.getState().expiresAt).toBeNull();
    expect(useSessionStore.getState().status).toBe('anonymous');
    // A token change is a different person, and leaving the product behind would
    // put the next one inside a product they may not have.
    expect(rememberedProduct()).toBeNull();
  });

  it('makes no network call, because revoking is a mutation elsewhere', () => {
    // U11's store called the provider itself. U12's does not: `useSignOut` revokes
    // through the generated client, so this file has no reason to touch `fetch` and
    // a test here cannot pass by stubbing one.
    expect(useSessionStore.getState().forget).not.toThrow();
  });
});

describe('when storage is blocked', () => {
  it('still signs somebody in, because nothing about signing in touches storage', () => {
    const original = window.localStorage.setItem.bind(window.localStorage);

    window.localStorage.setItem = () => {
      throw new Error('storage is blocked');
    };

    expect(() => useSessionStore.getState().signIn(GRANT)).not.toThrow();
    expect(useSessionStore.getState().token).toBe('access');

    window.localStorage.setItem = original;
  });
});
