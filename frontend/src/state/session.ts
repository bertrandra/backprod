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

export const useSessionStore = create<SessionState>((set) => ({
  token: null,
  productCode: null,
  signIn: (token) => set({ token }),
  // The product goes too. A token change is a different person, and keeping
  // the previous product would leave the next one acting inside a product they
  // may not have.
  signOut: () => set({ token: null, productCode: null }),
  chooseProduct: (productCode) => set({ productCode }),
}));

/**
 * Read outside React — for the API client's middleware, which runs per request
 * and must see the current value rather than one captured at render.
 */
export const sessionSnapshot = {
  token: () => useSessionStore.getState().token,
  product: () => useSessionStore.getState().productCode,
};
