import { describe, expect, it } from 'vitest';

import type { Access } from '@/app/access/access';

import {
  bottomBarEntries,
  CONSOLE_NAV,
  TENANT_NAV,
  visibleNav,
  type NavSection,
} from './navigation';

/**
 * The exit criterion the roadmap words as "navigation entries appear and
 * disappear with `/me/permissions` — proven by a test that changes the fixture,
 * not by inspection".
 *
 * So every case here changes the permission list and asserts what the nav then
 * offers. Nothing asserts against a role name, because gating on one is the
 * defect §13 forbids.
 */

function access(permissions: string[], capabilities: string[] = []): Access {
  return { permissions, capabilities };
}

const idsOf = (sections: readonly NavSection[]): string[] =>
  sections.flatMap((section) => section.entries.map((entry) => entry.id));

describe('what the navigation offers', () => {
  it('offers nothing before the session has loaded', () => {
    // `undefined` is the pending state, and the honest answer is an empty nav
    // rather than everything — a nav that appeared full and then shrank would
    // show people entries they cannot use.
    expect(visibleNav(TENANT_NAV, undefined)).toEqual([]);
  });

  it('offers only what the permissions allow', () => {
    const sections = visibleNav(TENANT_NAV, access(['billing.read']));

    expect(idsOf(sections)).toEqual(['invoices']);
  });

  it('grows when a permission is added', () => {
    const before = idsOf(visibleNav(TENANT_NAV, access(['billing.read'])));
    const after = idsOf(visibleNav(TENANT_NAV, access(['billing.read', 'projects.read'])));

    expect(before).not.toContain('projects');
    expect(after).toContain('projects');
  });

  it('shrinks when one is taken away', () => {
    const before = idsOf(visibleNav(TENANT_NAV, access(['tax.read', 'billing.read'])));
    const after = idsOf(visibleNav(TENANT_NAV, access(['billing.read'])));

    expect(before).toContain('tax');
    expect(after).not.toContain('tax');
  });

  it('drops a section left with no entries rather than showing an empty heading', () => {
    // An empty heading tells someone a capability exists and that they do not
    // have it, which the nav has no reason to volunteer.
    const sections = visibleNav(TENANT_NAV, access(['billing.read']));

    expect(sections).toHaveLength(1);
    expect(sections[0]?.id).toBe('money');
  });

  it('never offers an entry for a permission the session does not carry', () => {
    const everything = TENANT_NAV.flatMap((s) => s.entries);
    const granted = ['projects.read'];
    const offered = visibleNav(TENANT_NAV, access(granted)).flatMap((s) => s.entries);

    for (const entry of offered) {
      expect(granted).toContain(entry.permission);
    }

    expect(offered.length).toBeLessThan(everything.length);
  });
});

describe('the phone bottom bar', () => {
  it('holds at most five destinations', () => {
    const all = TENANT_NAV.flatMap((s) => s.entries).map((e) => e.permission);

    const entries = bottomBarEntries(TENANT_NAV, access(all));

    expect(entries.length).toBeLessThanOrEqual(5);
    // More exist than fit, which is what the More sheet is for.
    expect(TENANT_NAV.flatMap((s) => s.entries).length).toBeGreaterThan(5);
  });

  it('fills with primary entries before secondary ones', () => {
    const entries = bottomBarEntries(TENANT_NAV, access(['projects.read', 'jobs.read']));

    // `jobs` is secondary and `projects` is not, so projects comes first even
    // though jobs is listed beside it in the same section.
    expect(entries.map((e) => e.id)).toEqual(['projects', 'jobs']);
  });

  it('is empty for a session with no permissions at all', () => {
    expect(bottomBarEntries(TENANT_NAV, access([]))).toEqual([]);
  });
});

describe('every entry', () => {
  it('names a permission, so nothing is visible before the session loads', () => {
    // "Your profile" was briefly ungated, which made the nav offer something
    // while it still knew nothing. Every entry is gated now, including that one:
    // the platform defines `account.read`.
    for (const entry of [...TENANT_NAV, ...CONSOLE_NAV].flatMap((s) => s.entries)) {
      expect(entry.permission).toMatch(/^[a-z][a-z_]*(\.[a-z][a-z_]*)+$/);
    }
  });
});

describe('the two navigations', () => {
  it('share no entry, so a tenant permission cannot reveal a console route', () => {
    const tenant = new Set(idsOf(TENANT_NAV.map((s) => ({ ...s }))));
    const console_ = idsOf(CONSOLE_NAV.map((s) => ({ ...s })));

    for (const id of console_) {
      expect(tenant.has(id)).toBe(false);
    }
  });

  it('gate the console on platform permissions, never on tenant ones', () => {
    // A person with every tenant permission this application knows about must
    // still see no console entry (non-negotiable #22).
    const tenantPermissions = TENANT_NAV.flatMap((s) => s.entries).map((e) => e.permission);

    expect(visibleNav(CONSOLE_NAV, access(tenantPermissions))).toEqual([]);
  });

  it('routes every console entry under /console/', () => {
    for (const entry of CONSOLE_NAV.flatMap((s) => s.entries)) {
      expect(entry.to.startsWith('/console/')).toBe(true);
    }
  });

  it('routes no tenant entry under /console/', () => {
    for (const entry of TENANT_NAV.flatMap((s) => s.entries)) {
      expect(entry.to.startsWith('/console/')).toBe(false);
    }
  });
});
