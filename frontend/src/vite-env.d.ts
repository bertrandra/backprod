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

/** Stamped by `vite.config.ts` at build time; read through `src/build.ts`, never directly. */
declare const __APP_VERSION__: string;
declare const __BUILD_COMMIT__: string;
declare const __BUILD_TIME__: string;
