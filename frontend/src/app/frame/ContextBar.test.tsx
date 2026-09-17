import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient } from '@/test-utils';

import { ContextBar } from './regions';

/**
 * Region A names the organisation the screen is inside — by its name. Eight
 * characters of its uuid stood there from U1 until the operator asked what
 * the "incomprehensible code" beside the product was.
 */
const PRODUCTS = { products: [{ id: 'p-1', code: 'atlas', name: 'Atlas', active: true }] };

const bar = (session: Record<string, unknown>, extra: Record<string, unknown> = {}) =>
  renderAtRoute(
    <ContextBar onOpenPalette={() => undefined} />,
    stubClient({
      'GET /api/v1/me': { data: session },
      'GET /api/v1/products': { data: PRODUCTS },
      ...extra,
    }),
    { path: '/' },
  );

describe('the organisation in the bar', () => {
  it('is named, with the id kept on hover for a support ticket', async () => {
    bar(SESSION, {
      'GET /api/v1/tenants/current': {
        data: { tenant: { id: SESSION.tenant_id, name: 'Acme Ltd', slug: 'acme', may_author_offers: false } },
      },
    });

    await waitFor(() => expect(screen.getByTestId('context-organisation').textContent).toBe('Acme Ltd'));
    expect(screen.getByTestId('context-organisation').getAttribute('title')).toBe(SESSION.tenant_id);
  });

  it('falls back to the id where the tenant cannot be read', async () => {
    // No `tenant.read`: the read is not attempted, and the bar still says
    // *something* about where the person is.
    bar({ ...SESSION, permissions: [] });

    await waitFor(() =>
      expect(screen.getByTestId('context-organisation').textContent).toBe(String(SESSION.tenant_id).slice(0, 8)),
    );
  });
});
