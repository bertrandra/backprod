import { describe, expect, it } from 'vitest';

import { CONSOLE_NAV, TENANT_NAV } from '@/app/frame/navigation';

import { buildRouter } from './router';

/**
 * U8's first exit criterion: **a tenant-app route is unreachable from the
 * console and vice versa.**
 *
 * `navigation.test.ts` proves the two *menus* are disjoint, which is a different
 * claim — a menu is what is offered, and a route is what exists. A console route
 * accidentally parented to the tenant shell would render inside the tenant frame,
 * with a product switcher and no amber band, and no navigation test would notice.
 *
 * So this asserts against the route tree the application actually builds. Every
 * route id carries its shell, because the two shells are the only children of
 * the root, and that is what makes the property checkable rather than a
 * convention.
 */
const TENANT_SHELL = '/tenant-shell';
const CONSOLE_SHELL = '/console-shell';

function routeIds(): string[] {
  const router = buildRouter();

  return Object.keys(router.routesById);
}

/**
 * The shells a pathname resolves through.
 *
 * `matchRoutes` answers `any` here for the same reason `useParams` does — the
 * route tree is built at runtime, so there is no generated union to infer from
 * (see `router.tsx`). Narrowed once, here, rather than leaking an `any` into
 * three assertions that would then compare anything to anything.
 */
function matchedRouteIds(pathname: string): string[] {
  const matches: unknown = buildRouter().matchRoutes({ pathname, search: {} } as never);

  return Array.isArray(matches)
    ? matches.map((match: unknown) =>
        // `in` narrows it, so nothing needs asserting afterwards.
        typeof match === 'object' && match !== null && 'routeId' in match
          ? String(match.routeId)
          : '',
      )
    : [];
}

function pathsByShell(): { tenant: string[]; console: string[] } {
  const ids = routeIds();

  return {
    tenant: ids.filter((id) => id.startsWith(`${TENANT_SHELL}/`)),
    console: ids.filter((id) => id.startsWith(`${CONSOLE_SHELL}/`)),
  };
}

describe('the two shells', () => {
  it('are the only children of the root, so every route belongs to exactly one', () => {
    const ids = routeIds();
    const unaccounted = ids.filter(
      (id) =>
        id !== '__root__' &&
        id !== TENANT_SHELL &&
        id !== CONSOLE_SHELL &&
        !id.startsWith(`${TENANT_SHELL}/`) &&
        !id.startsWith(`${CONSOLE_SHELL}/`),
    );

    // A route outside both shells would render with no frame at all — no
    // navigation, no context bar, and in the console's case no warning that a
    // tenant boundary is being crossed.
    expect(unaccounted).toEqual([]);
  });

  it('put every /console/ path under the console shell and nothing else there', () => {
    const { tenant, console: consoleRoutes } = pathsByShell();

    for (const id of consoleRoutes) {
      expect(id.replace(`${CONSOLE_SHELL}/`, '/')).toMatch(/^\/console\//);
    }

    // And the converse: no tenant route is a console path, which is what stops
    // `/console/audit` resolving inside the tenant frame.
    for (const id of tenant) {
      expect(id.replace(`${TENANT_SHELL}/`, '/')).not.toMatch(/^\/console\//);
    }
  });

  it('resolve a console path through the console shell only', () => {
    const matched = matchedRouteIds('/console/tenants');

    expect(matched).toContain(CONSOLE_SHELL);
    expect(matched).not.toContain(TENANT_SHELL);
  });

  it('resolve a tenant path through the tenant shell only', () => {
    const matched = matchedRouteIds('/invoices');

    expect(matched).toContain(TENANT_SHELL);
    expect(matched).not.toContain(CONSOLE_SHELL);
  });

  it('does not let the tenant catch-all swallow a console path', () => {
    // `$` lives under the tenant shell so a mistyped tenant link still lands
    // inside the frame. If it also caught `/console/...`, a console route
    // renamed by a typo would silently render the tenant "no such page" —
    // inside the tenant frame, for a platform staff member.
    const matched = matchedRouteIds('/console/audit');

    expect(matched).not.toContain(`${TENANT_SHELL}/$`);
    expect(matched).toContain(`${CONSOLE_SHELL}/console/audit`);
  });
});

describe('every navigation entry', () => {
  it('points at a route that exists', () => {
    // The quietest possible broken link: an entry whose `to` was renamed on one
    // side only. It is visible, clickable, and lands on a not-found.
    const ids = new Set(routeIds());
    const known = (to: string, shell: string) => ids.has(`${shell}${to}`);

    for (const entry of TENANT_NAV.flatMap((section) => section.entries)) {
      expect(known(entry.to, TENANT_SHELL)).toBe(true);
    }

    for (const entry of CONSOLE_NAV.flatMap((section) => section.entries)) {
      expect(known(entry.to, CONSOLE_SHELL)).toBe(true);
    }
  });
});

describe('what is left to build', () => {
  it('is nothing: no route renders a milestone placeholder', () => {
    // U1 through U7 each left routes saying "this arrives in Ux". U8 is the last
    // screen milestone, so a placeholder surviving here would be a promise with
    // no milestone behind it.
    const router = buildRouter();

    for (const id of Object.keys(router.routesById)) {
      expect(id).not.toMatch(/placeholder/i);
    }
  });
});
