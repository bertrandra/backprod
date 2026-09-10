import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  contextMiddleware,
  DEFAULT_BASE_URL,
  PRODUCT_HEADER,
  type ApiContext,
  type Schemas,
} from './client';

/**
 * Derived from the function under test rather than imported from
 * `openapi-fetch` — which the §8.1 lint rule forbids here, and rightly: a test
 * that reached for the library directly would keep passing after the client
 * stopped using it.
 */
type OnRequestParams = Parameters<
  NonNullable<ReturnType<typeof contextMiddleware>['onRequest']>
>[0];

/**
 * What only this layer can get wrong.
 *
 * The generated types are not tested here — they are regenerated and compared
 * by `gate:client`, which is a stronger check than any assertion about them
 * could be. What is tested is the configuration this file adds on top: the
 * ambient context reaching every request, and the generated contract actually
 * being connected to the client rather than merely present in the repository.
 */

function contextOf(token: string | null, product: string | null): ApiContext {
  return { token: () => token, product: () => product };
}

/** Runs the middleware over a request and hands back the headers it produced. */
async function headersFor(context: ApiContext): Promise<Headers> {
  const middleware = contextMiddleware(context);
  const request = new Request('https://example.test/api/v1/me');

  // openapi-fetch hands its hooks a merged-options object it does not export
  // and this middleware never reads. Built to the shape the hook actually uses
  // and cast once, rather than reconstructing a private type.
  const result = await middleware.onRequest?.({
    request,
    schemaPath: '/api/v1/me',
    params: {},
    id: 'test',
  } as OnRequestParams);

  return result instanceof Request ? result.headers : request.headers;
}

describe('the request context', () => {
  it('carries the token and the product on every request', async () => {
    const headers = await headersFor(contextOf('tok-abc', 'atlas'));

    expect(headers.get('Authorization')).toBe('Bearer tok-abc');
    expect(headers.get(PRODUCT_HEADER)).toBe('atlas');
  });

  it('omits a header it has no value for rather than sending an empty one', async () => {
    const headers = await headersFor(contextOf(null, null));

    // An empty Authorization is a malformed credential and an empty X-Product
    // claims a product that does not exist. Both would be refused, but this
    // layer should not be the thing building a request nobody meant to send.
    expect(headers.has('Authorization')).toBe(false);
    expect(headers.has(PRODUCT_HEADER)).toBe(false);
  });

  it('treats an empty string as no value', async () => {
    const headers = await headersFor(contextOf('', ''));

    expect(headers.has('Authorization')).toBe(false);
    expect(headers.has(PRODUCT_HEADER)).toBe(false);
  });

  it('reads the context on each request rather than capturing it once', async () => {
    let product = 'atlas';
    const context: ApiContext = { token: () => 'tok', product: () => product };

    expect((await headersFor(context)).get(PRODUCT_HEADER)).toBe('atlas');

    // The product switcher in region A changes this while the app runs, so a
    // client that had captured the value would keep addressing the old product.
    product = 'orbit';

    expect((await headersFor(context)).get(PRODUCT_HEADER)).toBe('orbit');
  });
});

describe('the generated contract', () => {
  it('is same-origin by default, so no request is cross-origin', () => {
    expect(DEFAULT_BASE_URL).toBe('/api/v1');
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
