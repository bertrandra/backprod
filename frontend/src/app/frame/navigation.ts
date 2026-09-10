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
 *
 * **The permission codes are not free-form.** Six of them were wrong when this
 * table was first written — guessed from the endpoint's name rather than read
 * from the backend — and every unit test still passed, because they exercised
 * the *mechanism* against fixtures this file invented rather than the *values*
 * against the platform. A nav entry naming a permission nobody can hold is
 * simply invisible, which is the quietest possible bug.
 *
 * `composer run gate:permissions` now checks every code here against the
 * permissions the migrations create, in both directions.
 */
export interface NavEntry {
  readonly id: string;
  readonly label: string;
  readonly to: string;
  /**
   * The permission this entry needs. Every entry has one: an ungated entry would
   * be visible before the session has loaded, which breaks the rule below that
   * the nav offers nothing until it knows what to offer. "Your profile" looked
   * like the exception and is not — the platform defines `account.read`.
   */
  readonly permission: string;
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
      { id: 'projects', label: 'Projects', to: '/projects', permission: 'projects.read' },
      { id: 'jobs', label: 'Jobs', to: '/jobs', permission: 'jobs.read', secondary: true },
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
    id: 'inbox',
    label: 'Inbox',
    entries: [
      // Both read permissions, not the manage ones: reaching the screen is what
      // the nav decides, and a person who can read their inbox but not mark it
      // read must still be able to open it.
      {
        id: 'notifications',
        label: 'Notifications',
        to: '/notifications',
        permission: 'notifications.read',
      },
      {
        id: 'conversations',
        label: 'Conversations',
        to: '/conversations',
        permission: 'messages.read',
      },
    ],
  },
  {
    id: 'organisation',
    label: 'Organisation',
    entries: [
      { id: 'organisation', label: 'Organisation', to: '/organisation', permission: 'tenant.read', secondary: true },
      { id: 'members', label: 'Members', to: '/members', permission: 'members.read', secondary: true },
      { id: 'profile', label: 'Your profile', to: '/profile', permission: 'account.read', secondary: true },
      { id: 'branding', label: 'Branding', to: '/branding', permission: 'skin.manage', secondary: true },
      {
        id: 'notification-settings',
        label: 'Notification settings',
        to: '/notification-settings',
        permission: 'notifications.read',
        secondary: true,
      },
    ],
  },
];

/** The console's sections. A separate tree, never merged (non-negotiable #22). */
export const CONSOLE_NAV: readonly NavSection[] = [
  {
    id: 'support',
    label: 'Support',
    entries: [
      { id: 'tenants', label: 'Tenants', to: '/console/tenants', permission: 'staff.tenants.read' },
      { id: 'threads', label: 'Conversations', to: '/console/conversations', permission: 'support.read' },
      { id: 'access-log', label: 'Access log', to: '/console/access-log', permission: 'staff.access_log.read', secondary: true },
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
      entries: section.entries.filter((entry) => can(access, entry.permission)),
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
