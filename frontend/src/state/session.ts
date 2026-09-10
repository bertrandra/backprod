import { create } from 'zustand';

import { revoke, type Grant } from '@/api/auth';

/**
 * What the client needs on every request, and nothing else.
 *
 * Client state, so Zustand rather than TanStack Query (§8): the token and the
 * chosen product are not server data — they are what this browser is currently
 * acting as. Server data about the session (who that is, what they may do)
 * belongs to `queries/session.ts`.
 *
 * The product is the *code*, not the id: `X-Product` carries a code and the
 * backend resolves it against the registry (D1). Storing the id would mean
 * translating on every request.
 */
/**
 * Three states, because "no token" was two things wearing one face.
 *
 * A first paint with no token is either "this person is not signed in" or "we
 * have a refresh token and are two hundred milliseconds from having an access
 * token" — and showing a sign-in form to somebody who is already signed in is
 * the second one rendered as the first. `restoring` is the difference.
 */
export type SessionStatus = 'restoring' | 'anonymous' | 'signed-in';

interface SessionState {
  token: string | null;
  productCode: string | null;
  status: SessionStatus;
  /** When the access token stops being accepted, so a renewal can be scheduled. */
  expiresAt: number | null;
  signIn: (grant: Grant) => void;
  signOut: () => void;
  /** No token, and not this browser's fault — a failed restore rather than a sign-out. */
  forget: () => void;
  chooseProduct: (code: string) => void;
}

/**
 * Where the chosen product survives a reload.
 *
 * It has to survive one somewhere. `?product=` seeds the first visit, but the
 * router validates search params through `parseViewState` and drops anything it
 * does not know — so the moment a person navigates inside the application the
 * parameter is gone, and a reload after that used to land on "No product
 * selected" with everything working and nothing visible.
 *
 * `localStorage` rather than the URL, because the alternative is carrying
 * `product` through every link in the application, which `useProductContext`
 * deliberately rejected: 34 screen areas each remembering to append it is 34
 * chances to forget. It is a per-browser convenience about what this tab is
 * acting as — not a secret, not server state, and not the authority on anything:
 * every request still carries the code and the backend still resolves and
 * authorises it.
 *
 * Every access is guarded. A private window, cleared site data or a browser set
 * to block storage all throw here, and none of them should stop the application
 * from starting.
 */
const PRODUCT_KEY = 'backprod.product';

/**
 * Where the refresh token survives a reload, and why that is the least bad
 * place for it.
 *
 * The access token stays in memory only: it is short-lived, and a reload can
 * fetch another. The refresh token has to outlive the tab or every reload is a
 * new sign-in, and a browser gives a page exactly three places to put it —
 * `localStorage`, a readable cookie, or nowhere.
 *
 * **All three are reachable by script, so none of them survives XSS.** The one
 * shape that would is an `HttpOnly` cookie, and that requires the *server* to
 * own the exchange: PHP would take the credentials, hold the refresh token, and
 * hand out a session cookie the browser cannot read. That is a better design and
 * a different one — the API authenticates a bearer token today (ADR-014), so it
 * is an architecture change rather than a storage change, and it is written down
 * in `docs/deploying-to-siteground.md` as the next thing rather than pretended
 * away here.
 *
 * What is done about it meanwhile: the token is scoped to this origin, cleared
 * on sign-out and on any refresh the provider refuses, and revoked at the
 * provider rather than merely dropped.
 */
const REFRESH_KEY = 'backprod.refresh';

/** The stored refresh token, or null — including when storage itself throws. */
export function rememberedRefreshToken(): string | null {
  try {
    const stored = window.localStorage.getItem(REFRESH_KEY);

    return stored === null || stored === '' ? null : stored;
  } catch {
    return null;
  }
}

function rememberRefreshToken(token: string | null): void {
  try {
    if (token === null) {
      window.localStorage.removeItem(REFRESH_KEY);
    } else {
      window.localStorage.setItem(REFRESH_KEY, token);
    }
  } catch {
    // A private window blocks storage. Signing in still works for this tab; it
    // just will not outlive a reload, which is a smaller loss than refusing to
    // sign the person in at all.
  }
}

export function rememberedProduct(): string | null {
  try {
    const stored = window.localStorage.getItem(PRODUCT_KEY);

    return stored === null || stored === '' ? null : stored;
  } catch {
    return null;
  }
}

function remember(productCode: string | null): void {
  try {
    if (productCode === null) {
      window.localStorage.removeItem(PRODUCT_KEY);
    } else {
      window.localStorage.setItem(PRODUCT_KEY, productCode);
    }
  } catch {
    // Nothing to do and nothing worth saying: the product still works for this
    // page's lifetime, it just will not outlive a reload.
  }
}

export const useSessionStore = create<SessionState>((set) => ({
  token: null,
  productCode: null,
  // `restoring` is the honest first state: nothing has been decided yet, and
  // whether there is a token to recover is a question only storage can answer.
  status: 'restoring',
  expiresAt: null,
  signIn: (grant) => {
    rememberRefreshToken(grant.refreshToken);
    set({ token: grant.accessToken, expiresAt: grant.expiresAt, status: 'signed-in' });
  },
  // The product goes too. A token change is a different person, and keeping
  // the previous product would leave the next one acting inside a product they
  // may not have — including in storage, so a reload cannot bring it back.
  signOut: () => {
    // Best effort, and deliberately not awaited: the local session must end now
    // whether or not the provider is reachable.
    const token = useSessionStore.getState().token;

    if (token !== null) {
      void revoke(token);
    }

    remember(null);
    rememberRefreshToken(null);
    set({ token: null, productCode: null, expiresAt: null, status: 'anonymous' });
  },
  /**
   * The same end state, without the revoke.
   *
   * Used when the provider has already refused the refresh token: there is
   * nothing left to revoke, and asking it to would be one more failed request
   * in front of a person who just wants the sign-in form.
   */
  forget: () => {
    remember(null);
    rememberRefreshToken(null);
    set({ token: null, productCode: null, expiresAt: null, status: 'anonymous' });
  },
  chooseProduct: (productCode) => {
    remember(productCode);
    set({ productCode });
  },
}));

/**
 * Read outside React — for the API client's middleware, which runs per request
 * and must see the current value rather than one captured at render.
 */
export const sessionSnapshot = {
  token: () => useSessionStore.getState().token,
  product: () => useSessionStore.getState().productCode,
};
