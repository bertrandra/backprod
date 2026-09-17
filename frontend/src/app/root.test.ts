import { describe, expect, it } from 'vitest';

import { atRoot, detectRoot, RESERVED, withRoot } from './root';
import { buildRouter } from './router';

/**
 * The root is addressing, and it is decided from the first path segment
 * alone. What must hold: an organisation's slug is a root, the application's
 * own first segments never are, and the reserved list is the route table's
 * — so a screen added at a new path cannot be mistaken for a tenant named
 * after it.
 */
describe('detecting the root', () => {
  it('reads an organisation slug as its root', () => {
    expect(detectRoot('/acme')).toEqual({ root: '/acme', slug: 'acme' });
    expect(detectRoot('/acme/')).toEqual({ root: '/acme', slug: 'acme' });
    expect(detectRoot('/acme/invoices?product=atlas')).toEqual({ root: '/acme', slug: 'acme' });
  });

  it('reads the bare host and every application path as no root', () => {
    expect(detectRoot('/')).toEqual({ root: '', slug: null });
    expect(detectRoot('')).toEqual({ root: '', slug: null });
    expect(detectRoot('/invoices')).toEqual({ root: '', slug: null });
    expect(detectRoot('/console/tenants')).toEqual({ root: '', slug: null });
    expect(detectRoot('/sign-in')).toEqual({ root: '', slug: null });
  });

  it('refuses a segment that is not a slug', () => {
    expect(detectRoot('/Acme')).toEqual({ root: '', slug: null });
    expect(detectRoot('/a b')).toEqual({ root: '', slug: null });
    expect(detectRoot('/-x')).toEqual({ root: '', slug: null });
  });

  it('reserves exactly the first segments the route table uses', () => {
    const router = buildRouter();
    const firsts = new Set(
      Object.keys(router.routesByPath)
        .map((path) => path.replace(/^\/+/, '').split('/')[0] ?? '')
        .filter((segment) => segment !== '' && !segment.startsWith('$')),
    );

    for (const segment of firsts) {
      expect(RESERVED.has(segment), `${segment} is a route and must be reserved`).toBe(true);
    }
  });
});

describe('addresses under the root', () => {
  it('prefixes a path with the root, and the landing page is the root itself', () => {
    expect(withRoot('', '/invoices')).toBe('/invoices');
    expect(withRoot('/acme', '/invoices')).toBe('/acme/invoices');
    expect(withRoot('/acme', 'invoices')).toBe('/acme/invoices');
    expect(withRoot('', '/')).toBe('/');
    expect(withRoot('/acme', '/')).toBe('/acme/');
  });

  it('knows the landing page with or without a trailing slash', () => {
    expect(atRoot('', '/')).toBe(true);
    expect(atRoot('', '')).toBe(true);
    expect(atRoot('', '/invoices')).toBe(false);
    expect(atRoot('/acme', '/acme')).toBe(true);
    expect(atRoot('/acme', '/acme/')).toBe(true);
    expect(atRoot('/acme', '/acme/invoices')).toBe(false);
  });
});
