import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { rememberedProduct, rememberedRefreshToken, useSessionStore } from './session';

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
  // The store revokes at the provider on sign-out. That is a network call, and
  // these tests are about what the browser remembers.
  vi.stubGlobal('fetch', () => Promise.resolve({ ok: true } as Response));
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

const GRANT = { accessToken: 'access', refreshToken: 'refresh', expiresAt: 4_000_000 };

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

describe('signing in', () => {
  it('keeps the access token in memory and the refresh token in storage', () => {
    useSessionStore.getState().signIn(GRANT);

    expect(useSessionStore.getState().token).toBe('access');
    expect(useSessionStore.getState().status).toBe('signed-in');
    // The access token is deliberately *not* stored: it is short-lived and a
    // reload can fetch another. Only the long-lived one has to survive.
    expect(window.localStorage.getItem('backprod.token')).toBeNull();
    expect(rememberedRefreshToken()).toBe('refresh');
  });

  it('records when the token expires, so a renewal can be scheduled', () => {
    useSessionStore.getState().signIn(GRANT);

    expect(useSessionStore.getState().expiresAt).toBe(4_000_000);
  });

  it('replaces the stored refresh token, because the provider rotates it', () => {
    useSessionStore.getState().signIn(GRANT);
    useSessionStore.getState().signIn({ ...GRANT, refreshToken: 'rotated' });

    // Keeping the first one would work exactly once more and then log the person
    // out at a moment unrelated to anything they did.
    expect(rememberedRefreshToken()).toBe('rotated');
  });
});

describe('signing out', () => {
  it('leaves nothing behind that could restore the session', () => {
    useSessionStore.getState().signIn(GRANT);
    useSessionStore.getState().signOut();

    expect(useSessionStore.getState().token).toBeNull();
    expect(useSessionStore.getState().expiresAt).toBeNull();
    expect(rememberedRefreshToken()).toBeNull();
    expect(useSessionStore.getState().status).toBe('anonymous');
  });

  it('asks the provider to revoke the credential rather than merely forgetting it', () => {
    // Configured, deliberately. This test failed first without it, and the code
    // was right: `revoke` sends nothing when no provider exists, so there was no
    // call to find. The premise only holds for a deployment that can sign
    // somebody in.
    vi.stubEnv('VITE_SUPABASE_URL', 'https://project.supabase.test');
    vi.stubEnv('VITE_SUPABASE_ANON_KEY', 'anon-key');

    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({ ok: true } as Response);
    });

    useSessionStore.getState().signIn(GRANT);
    useSessionStore.getState().signOut();

    // "Signed out" should mean the refresh token is dead, not mislaid. Without
    // the provider being told, it stays valid for its full lifetime.
    expect(calls.some((url) => url.endsWith('/auth/v1/logout'))).toBe(true);
  });

  it('completes even when the provider cannot be reached', () => {
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')));

    useSessionStore.getState().signIn(GRANT);

    expect(() => useSessionStore.getState().signOut()).not.toThrow();
    expect(useSessionStore.getState().token).toBeNull();
  });
});

describe('forgetting a session that could not be restored', () => {
  it('reaches the same end state without asking the provider to revoke anything', () => {
    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({ ok: true } as Response);
    });

    window.localStorage.setItem('backprod.refresh', 'stale');
    useSessionStore.getState().forget();

    expect(useSessionStore.getState().status).toBe('anonymous');
    expect(rememberedRefreshToken()).toBeNull();
    // There is nothing left to revoke: the provider already refused it. Asking
    // would be one more failed request in front of somebody who wants the form.
    expect(calls).toEqual([]);
  });
});

describe('the refresh token when storage is blocked', () => {
  it('signs the person in for this tab rather than refusing to sign them in', () => {
    const original = window.localStorage.setItem.bind(window.localStorage);

    window.localStorage.setItem = () => {
      throw new Error('storage is blocked');
    };

    expect(() => useSessionStore.getState().signIn(GRANT)).not.toThrow();
    expect(useSessionStore.getState().token).toBe('access');

    window.localStorage.setItem = original;
  });
});
