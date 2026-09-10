/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * The product to act in when the URL does not name one. Set per deployment: a
   * single-product install should not make everyone add `?product=` to a link.
   */
  readonly VITE_DEFAULT_PRODUCT?: string;
  /** Where the API is, in development. Proxied by Vite so the browser stays same-origin. */
  readonly VITE_API_ORIGIN?: string;
  /**
   * The Supabase project URL, e.g. `https://abcdefgh.supabase.co`. Baked into
   * the bundle at build time because the browser is what needs it. Absent, the
   * sign-in screen says the deployment has no identity provider — which is the
   * same thing the backend says with an empty `SUPABASE_JWKS`.
   */
  readonly VITE_SUPABASE_URL?: string;
  /**
   * The project's anon key. Public by design: it is in every bundle and grants
   * nothing on its own. Not a secret, and not the service-role key — that one
   * must never reach a browser.
   */
  readonly VITE_SUPABASE_ANON_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
