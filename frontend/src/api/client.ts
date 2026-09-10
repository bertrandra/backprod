import createClient, { type Middleware } from 'openapi-fetch';

import type { components, paths } from './generated/schema';

/**
 * The only module in this application that reaches the API.
 *
 * It **configures**; it does not define contracts. Every path, verb, parameter
 * and response shape comes from `generated/schema.d.ts`, which comes from
 * `openapi.json` (§8.1). Nothing here restates any of it, and if this file ever
 * contains a URL string that is not passed straight through to the generated
 * types, something has gone wrong.
 *
 * ESLint forbids `fetch`, `XMLHttpRequest` and every HTTP library everywhere
 * else in `src/`, and forbids importing `openapi-fetch` outside this file. The
 * rule is not a reminder — §8.1 says a single contract is defended by there
 * being nowhere else to write the call, and this is the somewhere.
 */

/** Re-exported so no call site reaches into the generated directory. */
export type { components, paths } from './generated/schema';

/** `Schemas['Invoice']` rather than a hand-written `Invoice`. */
export type Schemas = components['schemas'];

/**
 * How the client learns the request context.
 *
 * Product is the root context (CLAUDE.md) and the tenant is derived from
 * membership, so both are ambient rather than per-call: a screen that passed
 * them itself could pass the wrong ones, and 34 screen areas each remembering
 * to is 34 chances to forget.
 *
 * Functions rather than values because both change while the application runs —
 * the product switcher in region A, and a token that refreshes.
 */
export interface ApiContext {
  /** The current access token, or null when not signed in. */
  readonly token: () => string | null;
  /** The active product's code, or null before one is chosen. */
  readonly product: () => string | null;
}

export const PRODUCT_HEADER = 'X-Product';

/**
 * Attaches the ambient context to every request.
 *
 * Absent rather than empty when unknown: an empty `Authorization` header is a
 * malformed credential, and an empty `X-Product` is a claim to a product that
 * does not exist. The backend refuses both, but it should be refusing a request
 * nobody meant to send rather than one this layer built badly.
 *
 * Exported so it can be tested as what it is — a transform from context to a
 * request — without standing up a client or stubbing `fetch`.
 */
export function contextMiddleware(context: ApiContext): Middleware {
  return {
    onRequest({ request }) {
      const token = context.token();

      if (token !== null && token !== '') {
        request.headers.set('Authorization', `Bearer ${token}`);
      }

      const product = context.product();

      if (product !== null && product !== '') {
        request.headers.set(PRODUCT_HEADER, product);
      }

      return request;
    },
  };
}

export interface ClientOptions {
  readonly baseUrl?: string;
  readonly context: ApiContext;
}

/**
 * The base URL is a path, not an origin, by default.
 *
 * Same-origin in production and proxied in development, so the browser makes no
 * cross-origin request and §31's strict CORS is never the thing standing
 * between a developer and a working page — which is how a permissive
 * development-only header gets added and then shipped.
 */
export const DEFAULT_BASE_URL = '/api/v1';

export function createApiClient({ baseUrl = DEFAULT_BASE_URL, context }: ClientOptions) {
  const client = createClient<paths>({ baseUrl });

  client.use(contextMiddleware(context));

  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;
