/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * The product to act in when the URL does not name one. Set per deployment: a
   * single-product install should not make everyone add `?product=` to a link.
   */
  readonly VITE_DEFAULT_PRODUCT?: string;
  /** Where the API is, in development. Proxied by Vite so the browser stays same-origin. */
  readonly VITE_API_ORIGIN?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
