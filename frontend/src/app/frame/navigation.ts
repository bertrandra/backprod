import { can, type Access } from '@/app/access/access';

/**
 * What the primary nav (region B) offers.
 *
 * A table, not a tree of conditionals. Each entry names the permission it
 * needs, and the permission is a string the API returned — so the nav is
 * derived from data and adding a section is a row here rather than an `if`
 * somewhere.
 *
 * Sections mirror ui-spec.md §3. The areas inside them arrive with their
 * milestones (U2–U8); this is the shell's view of what exists.
 */
export interface NavEntry {
  readonly id: string;
  readonly label: string;
  readonly to: string;
  /** Absent means always available to a signed-in member. */
  readonly permission?: string;
  /** Kept out of the phone's bottom bar; reachable under "More". */
  readonly secondary?: boolean;
}

export interface NavSection {
  readonly id: string;
  readonly label: string;
  readonly entries: readonly NavEntry[];
}

/** The tenant application's sections. */
export const TENANT_NAV: readonly NavSection[] = [
  {
    id: 'work',
    label: 'Work',
    entries: [
      { id: 'projects', label: 'Projects', to: '/projects', permission: 'project.read' },
      { id: 'jobs', label: 'Jobs', to: '/jobs', permission: 'job.read', secondary: true },
    ],
  },
  {
    id: 'commerce',
    label: 'Commerce',
    entries: [
      { id: 'catalogue', label: 'Catalogue', to: '/catalogue', permission: 'catalog.read' },
      { id: 'quotes', label: 'Quotes', to: '/quotes', permission: 'sales.read', secondary: true },
      { id: 'orders', label: 'Orders', to: '/orders', permission: 'sales.read', secondary: true },
    ],
  },
  {
    id: 'money',
    label: 'Money',
    entries: [
      { id: 'subscription', label: 'Subscription', to: '/subscription', permission: 'subscription.read' },
      { id: 'invoices', label: 'Invoices', to: '/invoices', permission: 'billing.read' },
      { id: 'tax', label: 'Tax', to: '/tax', permission: 'tax.read', secondary: true },
    ],
  },
  {
    id: 'organisation',
    label: 'Organisation',
    entries: [
      { id: 'members', label: 'Members', to: '/members', permission: 'tenant.read', secondary: true },
      { id: 'branding', label: 'Branding', to: '/branding', permission: 'skin.manage', secondary: true },
    ],
  },
];

/** The console's sections. A separate tree, never merged (non-negotiable #22). */
export const CONSOLE_NAV: readonly NavSection[] = [
  {
    id: 'support',
    label: 'Support',
    entries: [
      { id: 'tenants', label: 'Tenants', to: '/console/tenants', permission: 'support.tenant.read' },
      { id: 'threads', label: 'Conversations', to: '/console/conversations', permission: 'support.conversation.read' },
      { id: 'access-log', label: 'Access log', to: '/console/access-log', permission: 'staff.access.read', secondary: true },
    ],
  },
  {
    id: 'administration',
    label: 'Administration',
    entries: [
      { id: 'metrics', label: 'Metrics', to: '/console/metrics', permission: 'admin.finance.read' },
      { id: 'directory', label: 'Directory', to: '/console/directory', permission: 'admin.directory.read' },
      { id: 'queue', label: 'Queue', to: '/console/queue', permission: 'admin.health.read' },
      { id: 'audit', label: 'Audit', to: '/console/audit', permission: 'admin.audit.read', secondary: true },
    ],
  },
];

/**
 * Drops what this person may not reach, and then drops a section left empty.
 *
 * A section heading with nothing under it tells someone a capability exists and
 * that they do not have it, which is information the nav has no reason to
 * volunteer.
 */
export function visibleNav(
  sections: readonly NavSection[],
  access: Access | undefined,
): readonly NavSection[] {
  return sections
    .map((section) => ({
      ...section,
      entries: section.entries.filter(
        (entry) => entry.permission === undefined || can(access, entry.permission),
      ),
    }))
    .filter((section) => section.entries.length > 0);
}

/** The phone's bottom bar: at most five, primary entries first. */
export function bottomBarEntries(
  sections: readonly NavSection[],
  access: Access | undefined,
  limit = 5,
): readonly NavEntry[] {
  const entries = visibleNav(sections, access).flatMap((section) => section.entries);

  return [
    ...entries.filter((entry) => entry.secondary !== true),
    ...entries.filter((entry) => entry.secondary === true),
  ].slice(0, limit);
}
