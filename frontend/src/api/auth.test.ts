import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AuthFailure, authConfig, refreshGrant, revoke, signInWithPassword } from './auth';

/**
 * What only this module can get wrong.
 *
 * Every case here is a shape the provider can actually answer with, and the
 * question in each is the same: does a person end up looking at a sentence that
 * is true? The one that mattered most while writing it was the 200 with an
 * unexpected body — a cast would have produced a `Grant` holding `undefined` and
 * turned a provider fault into an authorisation bug three files away.
 */
const URL_BASE = 'https://project.supabase.test';

function configure(url: string = URL_BASE, key = 'anon-key'): void {
  vi.stubEnv('VITE_SUPABASE_URL', url);
  vi.stubEnv('VITE_SUPABASE_ANON_KEY', key);
}

/** A fetch stub that records what it was asked and answers what it was told. */
function stubFetch(answer: Partial<Response> | (() => never)): { calls: RequestInit[]; urls: string[] } {
  const calls: RequestInit[] = [];
  const urls: string[] = [];

  vi.stubGlobal('fetch', (url: string, init: RequestInit) => {
    urls.push(url);
    calls.push(init);

    if (typeof answer === 'function') {
      answer();
    }

    return Promise.resolve(answer as Response);
  });

  return { calls, urls };
}

/**
 * The first recorded call, narrowed.
 *
 * `noUncheckedIndexedAccess` is on, so `calls[0]` is possibly undefined — and it
 * genuinely is when the code under test sent nothing, which is what two of these
 * tests are about. Asserting it here fails with "nothing was sent" rather than
 * with a property access on undefined.
 */
function only<T>(recorded: readonly T[]): T {
  const first = recorded[0];

  if (first === undefined) {
    throw new Error('Nothing was sent to the provider.');
  }

  return first;
}

/**
 * The JSON a recorded request carried.
 *
 * `RequestInit['body']` is a `BodyInit`, which is seven things — a stream and a
 * form among them — so stringifying it blindly is the lint rule's point. This
 * module only ever sends a JSON string, and asserting that here is what makes the
 * assertion about the payload rather than about `[object Object]`.
 */
function sentJson(request: RequestInit): unknown {
  const { body } = request;

  if (typeof body !== 'string') {
    throw new Error('The request did not carry a JSON string body.');
  }

  return JSON.parse(body);
}

function ok(body: unknown): Partial<Response> {
  return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-09-10T12:00:00Z'));
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
  vi.useRealTimers();
});

describe('authConfig', () => {
  it('is null when the deployment configured no provider', () => {
    configure('', '');

    expect(authConfig()).toBeNull();
  });

  it('is null when only one of the two values is set', () => {
    configure(URL_BASE, '');

    expect(authConfig()).toBeNull();
  });

  it('drops a trailing slash so the composed URL has exactly one', () => {
    configure(`${URL_BASE}/`);

    expect(authConfig()?.url).toBe(URL_BASE);
  });

  it('keeps only the origin when the REST endpoint was configured by mistake', () => {
    // What a person actually pasted: the dashboard's Data API page shows this URL
    // far more prominently than the bare project one. Left in place it composes
    // `/rest/v1/auth/v1/token`, which 404s — and a 404 is reported as a rejected
    // credential, so the message blames their password.
    configure(`${URL_BASE}/rest/v1/`);

    expect(authConfig()?.url).toBe(URL_BASE);
  });

  it('keeps only the origin for any other path a dashboard might show', () => {
    configure(`${URL_BASE}/auth/v1`);

    expect(authConfig()?.url).toBe(URL_BASE);
  });

  it('treats a value that is not a URL as no provider at all', () => {
    configure('bmwjhxzyttyfimivvdnb.supabase.co');

    // No scheme, so not a URL. Null rather than a throw: the screen says the
    // deployment has no identity provider, which somebody can act on.
    expect(authConfig()).toBeNull();
  });
});

describe('signInWithPassword', () => {
  it('exchanges credentials for a grant whose expiry is measured against the local clock', async () => {
    configure();
    const { urls, calls } = stubFetch(
      ok({ access_token: 'access', refresh_token: 'refresh', expires_in: 3600 }),
    );

    const grant = await signInWithPassword('ada@acme.test', 'correct horse');

    expect(urls[0]).toBe(`${URL_BASE}/auth/v1/token?grant_type=password`);
    expect(grant.accessToken).toBe('access');
    expect(grant.refreshToken).toBe('refresh');
    // Not the provider's `expires_at`: a duration plus the local clock survives
    // a browser whose clock is wrong, and an absolute timestamp does not.
    expect(grant.expiresAt).toBe(Date.now() + 3_600_000);

    expect(sentJson(only(calls))).toEqual({
      email: 'ada@acme.test',
      password: 'correct horse',
    });
  });

  it('sends the anon key as the apikey header', async () => {
    configure();
    const { calls } = stubFetch(ok({ access_token: 'a', refresh_token: 'r', expires_in: 60 }));

    await signInWithPassword('ada@acme.test', 'x');

    expect(only(calls).headers).toMatchObject({ apikey: 'anon-key' });
  });

  it('refuses to send anything when no provider is configured', async () => {
    configure('', '');
    const { urls } = stubFetch(ok({}));

    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toThrow(AuthFailure);
    expect(urls).toEqual([]);
  });

  it('reports a wrong password without repeating the provider’s wording', async () => {
    configure();
    stubFetch({
      ok: false,
      status: 400,
      json: () => Promise.resolve({ error_code: 'invalid_credentials', msg: 'Invalid login credentials' }),
    });

    // The provider's own message distinguishes "no such user" from "wrong
    // password" on some paths, which is a disclosure a sign-in form should not
    // make. Ours says one thing for both.
    await expect(signInWithPassword('ada@acme.test', 'wrong')).rejects.toMatchObject({
      kind: 'credentials',
      message: 'That email and password do not match an account.',
    });
  });

  it('tells a throttled caller to wait rather than that their password is wrong', async () => {
    configure();
    stubFetch({ ok: false, status: 429, json: () => Promise.resolve({}) });

    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toMatchObject({
      kind: 'rate_limited',
    });
  });

  it('separates a provider fault from a rejected credential', async () => {
    configure();
    stubFetch({ ok: false, status: 503, json: () => Promise.resolve({}) });

    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toMatchObject({
      kind: 'provider',
    });
  });

  it('treats a rejected fetch as never having heard back', async () => {
    configure();
    stubFetch(() => {
      throw new TypeError('Failed to fetch');
    });

    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toMatchObject({
      kind: 'unreachable',
    });
  });

  it('rejects a 200 whose body is not a grant', async () => {
    configure();
    stubFetch(ok({ access_token: 'access' }));

    // The case a cast would have let through: no refresh token, no lifetime, and
    // a `Grant` that looks valid until something else fails on it.
    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toMatchObject({
      kind: 'provider',
    });
  });

  it('rejects a 200 that is not JSON at all', async () => {
    configure();
    stubFetch({
      ok: true,
      status: 200,
      json: () => Promise.reject(new SyntaxError('Unexpected token <')),
    });

    await expect(signInWithPassword('ada@acme.test', 'x')).rejects.toMatchObject({
      kind: 'provider',
    });
  });

  it('never schedules a renewal in the past, whatever lifetime it is given', async () => {
    configure();
    stubFetch(ok({ access_token: 'a', refresh_token: 'r', expires_in: 0 }));

    const grant = await signInWithPassword('ada@acme.test', 'x');

    expect(grant.expiresAt).toBeGreaterThan(Date.now());
  });
});

describe('refreshGrant', () => {
  it('uses the refresh grant type and sends the token in the body', async () => {
    configure();
    const { urls, calls } = stubFetch(ok({ access_token: 'a2', refresh_token: 'r2', expires_in: 60 }));

    const grant = await refreshGrant('r1');

    expect(urls[0]).toBe(`${URL_BASE}/auth/v1/token?grant_type=refresh_token`);
    expect(sentJson(only(calls))).toEqual({ refresh_token: 'r1' });
    // The provider rotates the refresh token, so the new one has to be kept:
    // storing the old one again would work exactly once more.
    expect(grant.refreshToken).toBe('r2');
  });
});

describe('revoke', () => {
  it('sends the access token to the provider so the credential dies rather than being mislaid', async () => {
    configure();
    const { urls, calls } = stubFetch({ ok: true, status: 204, json: () => Promise.resolve({}) });

    await revoke('access');

    expect(urls[0]).toBe(`${URL_BASE}/auth/v1/logout`);
    expect(only(calls).headers).toMatchObject({ Authorization: 'Bearer access' });
  });

  it('never throws, because signing out locally must not depend on the network', async () => {
    configure();
    stubFetch(() => {
      throw new TypeError('Failed to fetch');
    });

    await expect(revoke('access')).resolves.toBeUndefined();
  });
});
