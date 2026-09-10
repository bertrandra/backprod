import { describe, expect, it } from 'vitest';

import { createApiClient, NETWORK_UNREACHABLE } from './client';
import { toApiError } from '@/queries/session';

describe('a request that never arrives', () => {
  it('becomes a 503 carrying the §10.4 envelope', async () => {
    const client = createApiClient({
      // An absolute base, because this test builds a real Request and jsdom has
      // no origin to resolve a relative path against.
      baseUrl: 'https://api.test',
      context: { product: () => 'atlas', token: () => null },
      fetch: () => Promise.reject(new TypeError('Failed to fetch')),
    });

    const { error, response } = await client.GET('/api/v1/me', {
      params: { header: { 'X-Product': 'atlas' } },
    });

    expect(response.status).toBe(503);

    const mapped = toApiError(response.status, error);

    expect(mapped.code).toBe(NETWORK_UNREACHABLE);
    expect(mapped.message).toMatch(/did not reach the server/i);
    expect(mapped.requestId).toBe('');
  });
});
