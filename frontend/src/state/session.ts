import { create } from 'zustand';

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

/** What a successful exchange gives this browser to work with. */
export interface Grant {
  readonly accessToken: string;
  /** Seconds. A duration, so a skewed clock schedules the renewal correctly anyway. */
  readonly expiresIn: number;
}

interface SessionState {
  token: string | null;
  productCode: string | null;
  /**
   * The URL root this page is on — `'/acme'`, or `''` for the bare host —
   * and the organisation's slug, sent as `X-Tenant` on every request so the
   * server can choose among a person's memberships. Set once at boot from
   * the address (`app/root.ts`); the bare host learns its slug from the
   * default tenant once the storefront has asked. Never a credential and
   * never forgotten on sign-out: it is where the person *is*, not who.
   */
  root: string;
  tenantSlug: string | null;
  enterRoot: (root: string, slug: string | null) => void;
  status: SessionStatus;
  /** When the access token stops being accepted, so a renewal can be scheduled. */
  expiresAt: number | null;
  signIn: (grant: Grant) => void;
  /**
   * The token, without declaring the person signed in.
   *
   * One caller, and it needs exactly this: the storefront has just created an
   * account and has one more authenticated call to make — the checkout for the
   * offer that was chosen — and `signIn` would swap the whole page out from
   * under that call, because `SignInGate` renders the application the instant
   * the status flips. So the token becomes usable first and the status follows
   * when the page navigates.
   *
   * Short-lived by construction: what ends this state is a real navigation,
   * after which the session is restored from the refresh cookie like any
   * other reload.
   */
  grantToken: (grant: Grant) => void;
  /**
   * No token, locally.
   *
   * There is only one of these now. U11 had `signOut` (which revoked at the
   * provider) and `forget` (which did not), because the store itself made the
   * network call. Revoking is `useSignOut`'s job now — a mutation, through the
   * generated client, like every other write — so what is left here is the state
   * change, and it is the same state change either way.
   */
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
 * Nothing about the refresh token is here any more, and that is the change.
 *
 * U11 kept it in `localStorage` because an external provider issued it and a
 * browser has nowhere better: every store a page can reach, a script can reach.
 * U12 moved issuance into PHP, so the server sets an `HttpOnly` cookie instead —
 * unreadable by script, sent only to `/api/v1/auth`, and revocable server-side.
 *
 * So this store holds the access token and nothing durable. A reload starts with
 * no token, asks `/auth/refresh`, and the browser proves who it is with a cookie
 * this code cannot see. The XSS caveat that was written into three documents is
 * gone with it.
 */

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
  root: '',
  tenantSlug: null,
  enterRoot: (root, tenantSlug) => set({ root, tenantSlug }),
  // `restoring` is the honest first state: nothing has been decided yet, and
  // whether there is a token to recover is a question only storage can answer.
  status: 'restoring',
  expiresAt: null,
  signIn: (grant) => {
    set({
      token: grant.accessToken,
      // Computed here from the duration the server sent, against this browser's
      // clock — the same clock the renewal timer will read.
      expiresAt: Date.now() + grant.expiresIn * 1000,
      status: 'signed-in',
    });
  },
  grantToken: (grant) => {
    set({ token: grant.accessToken, expiresAt: Date.now() + grant.expiresIn * 1000 });
  },
  // The product goes too. A token change is a different person, and keeping
  // the previous product would leave the next one acting inside a product they
  // may not have — including in storage, so a reload cannot bring it back.
  forget: () => {
    remember(null);
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
  tenant: () => useSessionStore.getState().tenantSlug,
};
