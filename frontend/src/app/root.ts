/**
 * The URL root a page is on (2026-09-17).
 *
 * Every organisation has a root of its own — `hostname/acme/` — and the
 * operator's own organisation is addressed by the bare host. The root is
 * *addressing*: for a stranger it says whose shop window this is, for a
 * member which of their memberships the page is about. It never grants
 * anything; the server still derives the tenant from membership (ADR-015)
 * and the slug only chooses among them, exactly as `X-Tenant` always did.
 *
 * Detected once, from the first path segment, before the router exists:
 * a segment that is not one of the application's own first segments is an
 * organisation's slug, and the router is built with it as its `basepath`,
 * so every `Link` and `navigate` carries it without being told. Detection is
 * synchronous on purpose — the router cannot wait for a network answer —
 * and the storefront confirms the slug with `showPublicTenant` afterwards,
 * showing "no such organisation" if it was a typo rather than a tenant.
 *
 * `RESERVED` is every first segment the route table uses, plus the ones the
 * server owns. A test holds it equal to the route table, so a screen added
 * at a new path cannot be mistaken for a tenant called after it.
 */
export const RESERVED: ReadonlySet<string> = new Set([
  'api',
  'assets',
  'billing-profile',
  'branding',
  'catalogue',
  'checkout',
  'console',
  'demo',
  'conversations',
  'credit-notes',
  'invoices',
  'jobs',
  'members',
  'notification-settings',
  'notifications',
  'offers',
  'orders',
  'organisation',
  'payments',
  'profile',
  'projects',
  'quotes',
  'sign-in',
  'sign-up',
  'subscription',
  'tax',
]);

export interface Root {
  /** `'/acme'` for an organisation's root, `''` for the bare host. */
  readonly root: string;
  /** The slug, or null on the bare host. */
  readonly slug: string | null;
}

const SLUG = /^[a-z0-9][a-z0-9-]{0,62}$/;

export function detectRoot(pathname: string): Root {
  const first = pathname.replace(/^\/+/, '').split('/')[0] ?? '';

  if (first === '' || RESERVED.has(first) || !SLUG.test(first)) {
    return { root: '', slug: null };
  }

  return { root: `/${first}`, slug: first };
}

/**
 * A path under the current root, for the few places that write an `href`
 * or a `window.location` themselves rather than through the router.
 */
export function withRoot(root: string, path: string): string {
  if (path === '' || path === '/') {
    return root === '' ? '/' : `${root}/`;
  }

  return `${root}${path.startsWith('/') ? path : `/${path}`}`;
}

/** Whether a pathname is the root itself — the landing page. */
export function atRoot(root: string, pathname: string): boolean {
  const trimmed = pathname.replace(/\/+$/, '');

  return trimmed === root || (root === '' && trimmed === '');
}
