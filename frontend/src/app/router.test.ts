import { describe, expect, it } from 'vitest';

import { APP_NAV } from '@/app/frame/navigation';

import { buildRouter } from './router';

/**
 * What the route tree owes, now that there is one shell instead of two.
 *
 * The old version of this file proved "a tenant route is unreachable from the
 * console and vice versa" by checking which of two shells each route hung off.
 * That property is gone because the reason for it was wrong: #22 is about
 * authorisation, the server enforces it, and splitting the interface as well only
 * hid the platform's own screens from the person running it.
 *
 * What is still worth asserting against the tree the application actually builds:
 * every screen renders inside the frame, the catch-all does not swallow a real
 * path, and every navigation entry points somewhere that exists. The last one is
 * the quietest possible broken link — an entry whose `to` was renamed on one side
 * only is visible, clickable, and lands on a not-found.
 */
const SHELL = '/app-shell';

function routeIds(): string[] {
  return Object.keys(buildRouter().routesById);
}

/**
 * The route ids a pathname resolves through.
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

/**
 * The one route that is legitimately outside the shell.
 *
 * Enumerated rather than exempted by a pattern, and listed here rather than
 * loosened inside the assertion: the check below is about a route accidentally
 * escaping its frame, and the way to keep it meaningful is for every deliberate
 * escape to be a line somebody had to write.
 */
const OUTSIDE_THE_SHELL = ['/sign-in'];

describe('the shell', () => {
  it('is the only child of the root, apart from signing in', () => {
    const unaccounted = routeIds().filter(
      (id) => id !== '__root__' && id !== SHELL && !id.startsWith(`${SHELL}/`),
    );

    // A route outside the shell renders with no frame at all — no navigation, no
    // context bar, no warning when a tenant boundary is being crossed. That is
    // wrong for every screen in the application and right for exactly one:
    // somebody signing in has no session, so a shell would be a frame around
    // nothing it could fill in.
    expect(unaccounted).toEqual(OUTSIDE_THE_SHELL);
  });

  it('renders the platform screens inside it, at the paths they always had', () => {
    const platform = routeIds().filter((id) => id.startsWith(`${SHELL}/console`));

    // The paths did not change when the shells merged, so every link ever
    // written still resolves — and the prefix still says, in the address, which
    // authority a screen answers to.
    expect(platform.length).toBeGreaterThan(10);
    for (const id of platform) {
      expect(id.replace(`${SHELL}/`, '/')).toMatch(/^\/console(\/|$)/);
    }
  });

  it('resolves a platform path to its own route and not to the catch-all', () => {
    // `$` lives under the shell so a mistyped link still lands inside the frame.
    // If it also caught `/console/...`, a platform route renamed by a typo would
    // silently render "no such page" instead of failing loudly in review.
    const matched = matchedRouteIds('/console/audit');

    expect(matched).toContain(SHELL);
    expect(matched).toContain(`${SHELL}/console/audit`);
    expect(matched).not.toContain(`${SHELL}/$`);
  });

  it('resolves a tenant path to its own route', () => {
    const matched = matchedRouteIds('/invoices');

    expect(matched).toContain(SHELL);
    expect(matched).toContain(`${SHELL}/invoices`);
    expect(matched).not.toContain(`${SHELL}/$`);
  });

  it('still catches a path nobody declared', () => {
    expect(matchedRouteIds('/not-a-screen')).toContain(`${SHELL}/$`);
  });
});

describe('every navigation entry', () => {
  it('points at a route that exists', () => {
    // One shell means one prefix, and one loop covering both authorities — which
    // is stricter than the two it replaced, because neither list can be forgotten.
    const ids = new Set(routeIds());

    for (const entry of APP_NAV.flatMap((section) => section.entries)) {
      expect(ids.has(`${SHELL}${entry.to}`), `${entry.id} → ${entry.to}`).toBe(true);
    }
  });
});

describe('what is left to build', () => {
  it('is nothing: no route renders a milestone placeholder', () => {
    // U1 through U7 each left routes saying "this arrives in Ux". U8 is the last
    // screen milestone, so a placeholder surviving here would be a promise with
    // no milestone behind it.
    for (const id of routeIds()) {
      expect(id).not.toMatch(/placeholder/i);
    }
  });
});
