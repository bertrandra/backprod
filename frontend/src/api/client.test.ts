import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  ambientParams,
  contextMiddleware,
  createApiClient,
  DEFAULT_BASE_URL,
  NoProductChosen,
  PRODUCT_HEADER,
  TENANT_HEADER,
  type ApiContext,
  type paths,
  type Schemas,
} from './client';

/**
 * What only this layer can get wrong.
 *
 * The generated types are not tested here — they are regenerated and compared by
 * `gate:client`, which is a stronger check than any assertion about them could
 * be. What is tested is the configuration this file adds on top.
 *
 * The split is the thing to keep straight: the **token** is a security scheme in
 * the contract, so it is ambient and the middleware attaches it. **`X-Product`**
 * is a required *parameter*, so the generated types demand it at the call site
 * and `ambientParams` supplies the value. Middleware attaching the product would
 * satisfy that requirement invisibly, and a call site that had forgotten it
 * would still compile.
 */

/**
 * Derived from the function under test rather than imported from
 * `openapi-fetch`, which the §8.1 lint rule forbids here — and rightly: a test
 * that reached for the library directly would keep passing after the client
 * stopped using it.
 */
type OnRequestParams = Parameters<
  NonNullable<ReturnType<typeof contextMiddleware>['onRequest']>
>[0];

function contextOf(token: string | null, product: string | null): ApiContext {
  return { token: () => token, product: () => product };
}

async function headersFor(context: ApiContext): Promise<Headers> {
  const middleware = contextMiddleware(context);
  const request = new Request('https://example.test/api/v1/me');

  // openapi-fetch hands its hooks a merged-options object it does not export and
  // this middleware never reads. Built to the shape the hook actually uses and
  // cast once, rather than reconstructing a private type.
  const result = await middleware.onRequest?.({
    request,
    schemaPath: '/api/v1/me',
    params: {},
    id: 'test',
  } as OnRequestParams);

  return result instanceof Request ? result.headers : request.headers;
}

describe('the bearer token', () => {
  it('is attached to every request, because it is a security scheme', async () => {
    // `bearerAuth` is declared globally in the contract, so it applies to
    // everything and no call site should be repeating it.
    const headers = await headersFor(contextOf('tok-abc', 'atlas'));

    expect(headers.get('Authorization')).toBe('Bearer tok-abc');
  });

  it('is omitted rather than sent empty when there is none', async () => {
    // An empty Authorization is a malformed credential. The backend refuses it
    // either way, but it should be refusing a request nobody meant to send.
    const headers = await headersFor(contextOf(null, 'atlas'));

    expect(headers.has('Authorization')).toBe(false);
  });

  it('treats an empty string as no token', async () => {
    expect((await headersFor(contextOf('', 'atlas'))).has('Authorization')).toBe(false);
  });

  it('is read per request, not captured once', async () => {
    let token = 'first';
    const context: ApiContext = { token: () => token, product: () => 'atlas' };

    expect((await headersFor(context)).get('Authorization')).toBe('Bearer first');

    // Tokens refresh while the application runs.
    token = 'second';

    expect((await headersFor(context)).get('Authorization')).toBe('Bearer second');
  });

  it('does not attach the product — that is a parameter, not a scheme', async () => {
    const headers = await headersFor(contextOf('tok', 'atlas'));

    expect(headers.has(PRODUCT_HEADER)).toBe(false);
  });
});

describe('the request context as a parameter', () => {
  it('carries the product the contract requires', () => {
    expect(ambientParams(contextOf('tok', 'atlas'))).toEqual({
      params: { header: { [PRODUCT_HEADER]: 'atlas' } },
    });
  });

  it('refuses to build a request with no product rather than sending one', () => {
    // A resource request without a product is meaningless (§12.1). Failing here
    // gives a far better message than a 400 from the far end.
    expect(() => ambientParams(contextOf('tok', null))).toThrow(NoProductChosen);
    expect(() => ambientParams(contextOf('tok', ''))).toThrow(NoProductChosen);
  });

  it('includes the tenant only when there is one to name', () => {
    const withTenant = ambientParams(contextOf('tok', 'atlas'), 'tenant-1');
    const without = ambientParams(contextOf('tok', 'atlas'), null);

    expect(withTenant.params.header[TENANT_HEADER]).toBe('tenant-1');
    expect(TENANT_HEADER in without.params.header).toBe(false);
    expect(TENANT_HEADER in ambientParams(contextOf('tok', 'atlas'), '').params.header).toBe(false);
  });

  it('reads the product per call, so switching product takes effect', () => {
    let product = 'atlas';
    const context: ApiContext = { token: () => 'tok', product: () => product };

    expect(ambientParams(context).params.header[PRODUCT_HEADER]).toBe('atlas');

    // The product switcher in region A changes this while the app runs.
    product = 'orbit';

    expect(ambientParams(context).params.header[PRODUCT_HEADER]).toBe('orbit');
  });
});

describe('the generated contract', () => {
  it('is same-origin by default, so no request is cross-origin', () => {
    expect(DEFAULT_BASE_URL.startsWith('http')).toBe(false);
  });

  it('describes schemas the application can name without redeclaring them', () => {
    // If this stops compiling, the contract renamed or dropped a schema and the
    // frontend has been told — at build time, which is the point of §8.1.
    expectTypeOf<Schemas['Invoice']>().toBeObject();
    expectTypeOf<Schemas['Money']>().toBeObject();

    // Money is integer minor units plus a currency, never a float, and the
    // contract makes both required. Checking the field rather than the shape,
    // because a Money that had lost its amount would still be an object.
    expectTypeOf<Schemas['Money']['minor_units']>().toEqualTypeOf<number>();
  });
});

describe('the URL a request actually goes to', () => {
  /**
   * The check that was missing.
   *
   * The old assertion here read `expect(DEFAULT_BASE_URL).toBe('/api/v1')` — it
   * described the value rather than what the value *did*, and so it enshrined a
   * defect instead of catching it: the contract's paths already begin with
   * `/api/v1`, so that base composed `/api/v1/api/v1/me` and every live request
   * would have 404'd. Nothing else could see it, because the screen tests
   * replace the client and never compose a URL at all.
   */
  async function pathFor(baseUrl: string): Promise<string> {
    let seen = '';

    const client = createApiClient({
      baseUrl,
      context: contextOf('t', 'atlas'),
      fetch: (request) => {
        seen = request.url;

        return Promise.resolve(
          new Response('{}', { headers: { 'content-type': 'application/json' } }),
        );
      },
    });

    await client.GET('/api/v1/me', { params: { header: { 'X-Product': 'atlas' } } });

    return new URL(seen).pathname;
  }

  it('does not repeat a prefix the contract already carries', () => {
    // Every path in the generated contract begins with /api/v1 — this is one of
    // them, named as the types name it.
    const contractPath: keyof paths = '/api/v1/me';
    expect(contractPath.startsWith('/api/v1')).toBe(true);

    // So the base must not also carry it. This is the assertion that would have
    // caught the doubled prefix, and the one the old test should have been.
    expect(DEFAULT_BASE_URL).not.toContain('/api/v1');
  });

  /**
   * An explicit origin, because Node cannot build a `Request` from a relative
   * URL — a browser resolves it against the document and this environment
   * refuses it. The composition being asserted is the same one either way: the
   * client adds the base and nothing else.
   */
  it('carries the contract path exactly once', async () => {
    expect(await pathFor('https://example.test')).toBe('/api/v1/me');
  });

  it('would double the prefix if the base carried one — which is why it must not', async () => {
    // The defect, reproduced deliberately: this is what the application did on
    // every request from U0 to U5.
    expect(await pathFor('https://example.test/api/v1')).toBe('/api/v1/api/v1/me');
  });
});
