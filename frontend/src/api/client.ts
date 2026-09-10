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
export type { components, operations, paths } from './generated/schema';

import type { operations as generatedOperations } from './generated/schema';

/** `Schemas['Invoice']` rather than a hand-written `Invoice`. */
export type Schemas = components['schemas'];

/**
 * The operations, for a response shape the contract declares **inline** rather
 * than as a named schema — `eraseUser`'s report, say. Restating such a shape by
 * hand compiles and then goes on compiling after the contract changes, which is
 * the one failure mode generating the client was meant to remove.
 */
export type Operations = generatedOperations;

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
export const TENANT_HEADER = 'X-Tenant';

/**
 * Attaches the bearer token to every request.
 *
 * The token and nothing else, because the token is the one thing the contract
 * models as a **security scheme** rather than a parameter (`bearerAuth`,
 * applied globally). A security scheme is ambient by definition; a parameter is
 * not, which is why `X-Product` is handled by `ambient()` below instead.
 *
 * Absent rather than empty when there is no token: an empty `Authorization` is
 * a malformed credential, and the backend should be refusing a request nobody
 * meant to send rather than one this layer built badly.
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

      return request;
    },
  };
}

/**
 * The code a failure that never reached the server carries.
 *
 * Not one of §10.4's — the backend cannot send it, by definition. It is minted
 * here so that "the network is down" arrives at a screen in the same shape as
 * every other failure, and every `ErrorSurface` in the application can say
 * something true about it without 23 query modules learning about `fetch`.
 */
export const NETWORK_UNREACHABLE = 'NETWORK_UNREACHABLE';

/**
 * Turns a request that never arrived into an answer.
 *
 * `fetch` rejects when the network is unreachable — no response, no status, no
 * envelope — and that rejection propagated raw through every query in this
 * application. TanStack Query surfaced it as a plain `TypeError`, which
 * `ErrorSurface` could only render as *"Something went wrong"*, and the one
 * thing a person offline needs to be told is the one thing it could not say.
 *
 * So the rejection becomes a **synthetic 503** carrying the §10.4 envelope. It
 * is honest about being synthetic — the `request_id` is empty, because there is
 * no server log to key on — and it means offline handling is a property of the
 * transport rather than something each screen remembers.
 *
 * 503 rather than 0 or 599: it is the status whose meaning is "the thing you
 * asked is not reachable", and TanStack Query's retry logic already treats it
 * as worth another attempt, which for a dropped connection is right.
 */
export function offlineMiddleware(): Middleware {
  return {
    onError({ error }) {
      // An abort is not an outage. TanStack Query cancels in-flight queries when
      // a component unmounts, and reporting that as "you are offline" would put
      // a false alarm on screen every time somebody navigated.
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }

      return new Response(
        JSON.stringify({
          error: {
            code: NETWORK_UNREACHABLE,
            message: 'The request did not reach the server.',
            details: {},
            request_id: '',
          },
        }),
        { status: 503, headers: { 'Content-Type': 'application/json' } },
      );
    },
  };
}

/** Thrown when a request needs a product and none has been chosen. */
export class NoProductChosen extends Error {
  constructor() {
    super('This request needs a product, and none is selected.');
    this.name = 'NoProductChosen';
  }
}

export interface AmbientParams {
  params: {
    header: {
      'X-Product': string;
      'X-Tenant'?: string;
    };
  };
  /**
   * openapi-fetch's init type accepts arbitrary extra options (fetch overrides,
   * a per-call baseUrl), so its parameter carries an index signature and
   * anything passed to it must too.
   */
  [key: string]: unknown;
}

/**
 * The request context, as the parameter the contract says it is.
 *
 * `X-Product` is declared `required: true` in OpenAPI, so the generated types
 * demand it at every call site — and that is **better than attaching it in
 * middleware**, which was the first design here. Middleware would satisfy the
 * requirement invisibly, so a call that had forgotten it would still compile
 * and still work, right up until the middleware changed. As a parameter, a call
 * site that omits it **fails to build**.
 *
 * UD6's intent survives: no call site *decides* the product, and the value
 * still comes from exactly one place. What each call site does is say that it
 * needs it, which the contract already said.
 *
 * `X-Tenant` is optional — the backend derives the tenant from membership and
 * only needs telling when a person belongs to several. It is included when
 * known and omitted otherwise, never sent empty.
 */
export function ambientParams(context: ApiContext, tenantId?: string | null): AmbientParams {
  const product = context.product();

  if (product === null || product === '') {
    // Refused here rather than sent. A resource request without a product is
    // meaningless (§12.1) and the backend refuses it; failing in the caller
    // gives a far better message than a 400 from the far end.
    throw new NoProductChosen();
  }

  return {
    params: {
      header:
        tenantId === undefined || tenantId === null || tenantId === ''
          ? { 'X-Product': product }
          : { 'X-Product': product, 'X-Tenant': tenantId },
    },
  };
}

/**
 * The init fragment for a request whose body is a file.
 *
 * OpenAPI describes an `image/png` body as `type: string, format: binary`, and
 * `openapi-typescript` maps that to `string` — there is no TypeScript type a
 * generator could emit for "the bytes of a file". So the generated type asks for
 * a string and a `File` is what should actually be sent.
 *
 * The cast lives here, in the module whose job is transport, rather than at each
 * upload site. `bodySerializer` is what stops openapi-fetch JSON-encoding it.
 *
 * The content type is the file's own. The API sniffs the bytes rather than
 * trusting the header (ADR-028), so a cautious `application/octet-stream` would
 * be refused and a lie would be caught — the honest value is the useful one.
 */
export function binaryBody(file: File): {
  body: string;
  headers: Record<string, string>;
  bodySerializer: (body: unknown) => BodyInit;
} {
  return {
    body: file as unknown as string,
    headers: { 'Content-Type': file.type },
    bodySerializer: (body: unknown) => body as BodyInit,
  };
}

export interface ClientOptions {
  readonly baseUrl?: string;
  readonly context: ApiContext;
  /**
   * The fetch implementation, for tests that need to see the request this
   * client actually builds.
   *
   * It exists because nothing else could observe that: the screen tests replace
   * the whole client, so the URL composition above had no test at all until it
   * was wrong in production.
   */
  readonly fetch?: (request: Request) => Promise<Response>;
}

/**
 * Empty, because **the contract's paths already carry `/api/v1`**.
 *
 * Every key in `paths` is `/api/v1/…`, so a base of `/api/v1` composed
 * `/api/v1/api/v1/me` and every live request would have 404'd. It shipped from
 * U0 to U5 unnoticed: the unit tests replace the client wholesale so they never
 * compose a URL, the Playwright stubs match with patterns that a doubled path
 * satisfies too, and the one test that looked at this asserted the *value* was
 * `/api/v1` rather than asserting what it composed to. A vite proxy error in a
 * passing run is what finally showed it.
 *
 * Same-origin either way: production serves both from one origin and
 * development proxies `/api`, so the browser makes no cross-origin request and
 * §31's strict CORS is never the thing standing between a developer and a
 * working page — which is how a permissive development-only header gets added
 * and then shipped.
 */
export const DEFAULT_BASE_URL = '';

export function createApiClient({ baseUrl = DEFAULT_BASE_URL, context, fetch }: ClientOptions) {
  const client = createClient<paths>(fetch === undefined ? { baseUrl } : { baseUrl, fetch });

  client.use(contextMiddleware(context));
  // After the context middleware, so a request that was built correctly and
  // then failed to travel is the case this handles.
  client.use(offlineMiddleware());

  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;
