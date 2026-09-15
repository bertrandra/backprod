import { describe, expect, it } from 'vitest';

import type { Access } from '@/app/access/access';

import {
  APP_NAV,
  bottomBarEntries,
  firstTenantEntry,
  platformSections,
  visibleNav,
  type Authorities,
  type NavSection,
} from './navigation';

/**
 * The exit criterion the roadmap words as "navigation entries appear and
 * disappear with `/me/permissions` — proven by a test that changes the fixture,
 * not by inspection".
 *
 * So every case here changes a permission list and asserts what the nav then
 * offers. Nothing asserts against a role name, because gating on one is the
 * defect §13 forbids.
 *
 * **And since the two shells became one, this file carries the boundary.** What
 * used to be guaranteed by two arrays in two files is now guaranteed per entry by
 * its `scope`, and the assertions below are the proof: somebody holding every
 * tenant permission this application knows about still sees no platform entry,
 * and the reverse.
 */

function access(permissions: string[], capabilities: string[] = []): Access {
  return { permissions, capabilities };
}

/** Only a tenant session, no platform role at all. */
const asTenant = (permissions: string[]): Authorities => ({
  tenant: access(permissions),
  platform: undefined,
});

/** Only a platform role, no membership — a fresh installation's administrator. */
const asPlatform = (permissions: string[]): Authorities => ({
  tenant: undefined,
  platform: access(permissions),
});

const idsOf = (sections: readonly NavSection[]): string[] =>
  sections.flatMap((section) => section.entries.map((entry) => entry.id));

const entriesOf = (scope: 'tenant' | 'platform') =>
  APP_NAV.flatMap((section) => section.entries).filter((entry) => entry.scope === scope);

describe('what the navigation offers', () => {
  it('offers nothing before either answer has arrived', () => {
    // `undefined` is the pending state, and the honest answer is an empty nav
    // rather than everything — a nav that appeared full and then shrank would
    // show people entries they cannot use.
    expect(visibleNav(APP_NAV, { tenant: undefined, platform: undefined })).toEqual([]);
  });

  it('offers only what the permissions allow', () => {
    const sections = visibleNav(APP_NAV, asTenant(['billing.read']));

    // Everything `billing.read` opens, and nothing else. Written out in full
    // rather than counted, so an entry appearing under the wrong permission
    // fails here.
    expect(idsOf(sections)).toEqual(['invoices', 'credit-notes', 'billing-profile']);
  });

  it('grows when a permission is added', () => {
    const before = idsOf(visibleNav(APP_NAV, asTenant(['billing.read'])));
    const after = idsOf(visibleNav(APP_NAV, asTenant(['billing.read', 'projects.read'])));

    expect(before).not.toContain('projects');
    expect(after).toContain('projects');
  });

  it('shrinks when one is taken away', () => {
    const before = idsOf(visibleNav(APP_NAV, asTenant(['tax.read', 'billing.read'])));
    const after = idsOf(visibleNav(APP_NAV, asTenant(['billing.read'])));

    expect(before).toContain('tax');
    expect(after).not.toContain('tax');
  });

  it('drops a section left with no entries rather than showing an empty heading', () => {
    // An empty heading tells someone a capability exists and that they do not
    // have it, which the nav has no reason to volunteer.
    const sections = visibleNav(APP_NAV, asTenant(['billing.read']));

    expect(sections).toHaveLength(1);
    expect(sections[0]?.id).toBe('money');
  });

  it('never offers an entry for a permission nobody carries', () => {
    const granted = ['projects.read'];
    const offered = visibleNav(APP_NAV, asTenant(granted)).flatMap((s) => s.entries);

    for (const entry of offered) {
      expect(granted).toContain(entry.permission);
    }

    expect(offered.length).toBeLessThan(APP_NAV.flatMap((s) => s.entries).length);
  });
});

/**
 * The boundary, which one navigation has to carry explicitly.
 *
 * Non-negotiable #22 is about authorisation: a platform role never grants a
 * tenant membership and never the reverse. The server enforces it with two
 * contexts and a gate per route. What this navigation owes is narrower and still
 * worth proving — that an entry is only ever shown for the authority it names, so
 * no confusion of the two can put a screen in front of somebody by mistake.
 */
describe('the boundary between the two authorities', () => {
  it('shows no platform entry to somebody holding every tenant permission', () => {
    const everyTenantPermission = entriesOf('tenant').map((entry) => entry.permission);

    const offered = visibleNav(APP_NAV, asTenant(everyTenantPermission)).flatMap((s) => s.entries);

    expect(offered.length).toBeGreaterThan(0);
    for (const entry of offered) {
      expect(entry.scope).toBe('tenant');
    }
  });

  it('shows no tenant entry to somebody holding every platform permission', () => {
    // The mirror case, and the one a fresh installation actually produces: a
    // platform administrator whose membership carries nothing yet.
    const everyPlatformPermission = entriesOf('platform').map((entry) => entry.permission);

    const offered = visibleNav(APP_NAV, asPlatform(everyPlatformPermission)).flatMap(
      (s) => s.entries,
    );

    expect(offered.length).toBeGreaterThan(0);
    for (const entry of offered) {
      expect(entry.scope).toBe('platform');
    }
  });

  it('is not fooled by the same code appearing in the other authority', () => {
    // The mechanism, stated as its own case. Even if a tenant somehow carried a
    // string identical to a platform permission, the platform entry consults the
    // platform authority and finds nothing there.
    const platformCode = entriesOf('platform')[0]?.permission;

    expect(platformCode).toBeDefined();
    expect(visibleNav(APP_NAV, asTenant([platformCode ?? ''])).flatMap((s) => s.entries)).toEqual(
      [],
    );
  });

  it('shows both to somebody who holds both, which is the whole point', () => {
    const offered = idsOf(
      visibleNav(APP_NAV, {
        tenant: access(['projects.read']),
        platform: access(['staff.products.manage']),
      }),
    );

    // One person, one navigation, both authorities — and nothing to discover by
    // typing an address.
    expect(offered).toContain('projects');
    expect(offered).toContain('products');
  });

  it('keeps every platform entry under /console and every tenant entry out of it', () => {
    for (const entry of entriesOf('platform')) {
      expect(entry.to.startsWith('/console')).toBe(true);
    }

    for (const entry of entriesOf('tenant')) {
      expect(entry.to.startsWith('/console')).toBe(false);
    }
  });

  it('gives every entry an id of its own across the whole tree', () => {
    // One tree now, so a duplicate id would make a highlighted entry ambiguous
    // rather than merely surprising.
    const ids = APP_NAV.flatMap((s) => s.entries).map((e) => e.id);

    expect(new Set(ids).size).toBe(ids.length);
  });
});

describe('the phone bottom bar', () => {
  it('holds at most five destinations', () => {
    const all = APP_NAV.flatMap((s) => s.entries);

    const entries = bottomBarEntries(APP_NAV, {
      tenant: access(all.filter((e) => e.scope === 'tenant').map((e) => e.permission)),
      platform: access(all.filter((e) => e.scope === 'platform').map((e) => e.permission)),
    });

    expect(entries.length).toBeLessThanOrEqual(5);
    // More exist than fit, which is what the More sheet is for.
    expect(all.length).toBeGreaterThan(5);
  });

  it('fills with primary entries before secondary ones', () => {
    const entries = bottomBarEntries(APP_NAV, asTenant(['projects.read', 'jobs.read']));

    // `jobs` is secondary and `projects` is not, so projects comes first even
    // though jobs is listed beside it in the same section.
    expect(entries.map((e) => e.id)).toEqual(['projects', 'jobs']);
  });

  it('is empty for somebody with no permissions at all', () => {
    expect(bottomBarEntries(APP_NAV, asTenant([]))).toEqual([]);
  });
});

describe('every entry', () => {
  it('names a permission, so nothing is visible before the answers load', () => {
    // "Your profile" was briefly ungated, which made the nav offer something
    // while it still knew nothing. Every entry is gated now, including that one:
    // the platform defines `account.read`.
    for (const entry of APP_NAV.flatMap((s) => s.entries)) {
      expect(entry.permission).toMatch(/^[a-z][a-z_]*(\.[a-z][a-z_]*)+$/);
    }
  });

  it('declares which authority it answers to', () => {
    // The field that replaced two files. A missing one would be a type error;
    // this catches a value that is neither.
    for (const entry of APP_NAV.flatMap((s) => s.entries)) {
      expect(['tenant', 'platform']).toContain(entry.scope);
    }
  });
});

/**
 * The order is the order of operations, across the whole platform.
 *
 * The console used to open on support and put Products seventh, so the screen
 * somebody needed first was the one they could not find. A menu whose order
 * contradicts the order of operations teaches the wrong sequence every time it
 * is read.
 */
describe('the navigation is ordered by dependency', () => {
  it('opens on the chain a product has to complete, in that order', () => {
    const setup = APP_NAV[0];

    expect(setup?.id).toBe('setup');
    expect(setup?.entries.map((entry) => entry.id)).toEqual([
      'readiness',
      'products',
      'invoicing',
      'platform-catalogue',
      'storefront',
    ]);
  });

  it('puts invoicing before the catalogue, because an invoice must name its issuer', () => {
    const ids = APP_NAV.flatMap((section) => section.entries.map((entry) => entry.id));

    // A product can be priced and advertised and still refuse at the checkout.
    // Meeting that refusal after building a whole catalogue is the failure this
    // order exists to prevent.
    expect(ids.indexOf('invoicing')).toBeLessThan(ids.indexOf('platform-catalogue'));
    expect(ids.indexOf('platform-catalogue')).toBeLessThan(ids.indexOf('storefront'));
  });

  it('puts setting the platform up before working inside a tenant', () => {
    const sections = APP_NAV.map((section) => section.id);

    // Nothing exists for a tenant to do until the platform has a product with a
    // price on it. Somebody with no platform role never sees these sections and
    // starts at Work.
    expect(sections.indexOf('setup')).toBeLessThan(sections.indexOf('work'));
  });
});

describe("the console's own menu", () => {
  it('is the sections whose every entry answers to the platform', () => {
    const everything = asPlatform(entriesOf('platform').map((entry) => entry.permission));

    // Exactly the three the tree leads with; no tenant section is ever in it.
    expect(platformSections(visibleNav(APP_NAV, everything)).map((s) => s.id)).toEqual([
      'setup',
      'customers',
      'platform',
    ]);
  });

  it('offers nothing to somebody with no platform role', () => {
    const tenantOnly = asTenant(entriesOf('tenant').map((entry) => entry.permission));

    expect(platformSections(visibleNav(APP_NAV, tenantOnly))).toEqual([]);
  });

  it('shrinks with the permissions, section by section', () => {
    // Tenants alone: the "customers" section, nothing from setup or platform.
    const support = asPlatform(['staff.tenants.read']);

    expect(platformSections(visibleNav(APP_NAV, support)).map((s) => s.id)).toEqual(['customers']);
  });

  it('points the way back at the first tenant screen the person may open, else nowhere', () => {
    const both: Authorities = {
      tenant: access(['projects.read', 'billing.read']),
      platform: access(['staff.tenants.read']),
    };

    expect(firstTenantEntry(visibleNav(APP_NAV, both))?.to).toBe('/projects');
    expect(firstTenantEntry(visibleNav(APP_NAV, asPlatform(['staff.tenants.read'])))).toBeUndefined();
  });
});
