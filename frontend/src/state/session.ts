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
interface SessionState {
  token: string | null;
  productCode: string | null;
  signIn: (token: string) => void;
  signOut: () => void;
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
  signIn: (token) => set({ token }),
  // The product goes too. A token change is a different person, and keeping
  // the previous product would leave the next one acting inside a product they
  // may not have — including in storage, so a reload cannot bring it back.
  signOut: () => {
    remember(null);
    set({ token: null, productCode: null });
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
