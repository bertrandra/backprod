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
/**
 * Which authority an entry's permission belongs to.
 *
 * **This field is the whole safety mechanism of a single navigation.** The two
 * trees used to be kept apart by living in two arrays behind two shells, which
 * made "a tenant permission can never reveal a platform screen" a property of
 * the file layout — true, and implicit. Here it is explicit per entry: the scope
 * chooses *which* permission set is consulted, so a platform entry is only ever
 * checked against `/staff/me` and a tenant entry only against `/me/permissions`.
 * Holding `catalog.manage` in a tenant cannot light `staff.catalog.manage`'s
 * entry, because that entry never looks at the tenant's set at all.
 *
 * `gate:permissions` checks the two agree: a `staff.`/`support.`/`admin.` code
 * must be declared `platform`, and a tenant code `tenant`. A mismatch fails the
 * build rather than quietly hiding — or quietly showing — a screen.
 */
export type NavScope = 'tenant' | 'platform';

export interface NavEntry {
  readonly id: string;
  readonly label: string;
  readonly to: string;
  readonly scope: NavScope;
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

/**
 * The two authorities a person may hold, kept apart by name.
 *
 * One object rather than two arguments so that a caller cannot pass them in the
 * wrong order — which would be the one mistake that turns the mechanism above
 * inside out, and the one a type cannot catch when both are the same shape.
 */
export interface Authorities {
  readonly tenant: Access | undefined;
  readonly platform: Access | undefined;
}

/**
 * What the platform's menu setup leaves out, per authority — entry ids the
 * shell was told to hide, from `/me/navigation` and `/staff/me/navigation`.
 *
 * Kept apart by authority for the reason {@see Authorities} is: an id hidden
 * from the console must never hide a tenant entry of the same name, and the
 * server answers each question separately. Absent means nothing hidden —
 * the read has not answered, or failed — because the menu is courtesy and a
 * missing courtesy must not take the navigation with it.
 */
export interface Hidden {
  readonly tenant?: ReadonlySet<string> | undefined;
  readonly platform?: ReadonlySet<string> | undefined;
}

const NOTHING: ReadonlySet<string> = new Set();

/**
 * One navigation, for one person.
 *
 * **This used to be two trees behind two shells, and that was a mistake.**
 * Non-negotiable #22 says a platform role never grants a tenant membership and
 * never the reverse — a rule about *authorisation*, enforced on the server by two
 * contexts, two permission catalogues and two sets of gates. It says nothing
 * about interfaces. Splitting the UI as well was an inference, and it cost the
 * operator of this platform the same afternoon twice: the screen they needed was
 * behind an address nobody told them about.
 *
 * So there is one navigation and every entry declares which authority it answers
 * to. The rule holds where it was written to hold, and it holds *more* explicitly
 * than before: see {@see NavScope}.
 *
 * **Ordered by dependency across the whole platform.** Setting the platform up
 * comes before there is anything for a tenant to do, so the platform sections
 * lead. A person with no platform role never sees them and starts at Work.
 */
export const APP_NAV: readonly NavSection[] = [
  {
    id: 'setup',
    label: 'Set up',
    entries: [
      // The map of the chain, and where `/console` lands.
      { id: 'readiness', label: 'Setup', to: '/console/readiness', scope: 'platform', permission: 'staff.products.manage' },
      // The top of the model: a product is what tenants belong to and what
      // offers are priced for. `listProducts` resolves through membership, which
      // a platform role never grants, so this is the only screen that can answer
      // "what does this deployment host?".
      { id: 'products', label: 'Products', to: '/console/products', scope: 'platform', permission: 'staff.products.manage' },
      // Before the catalogue, because an invoice must name its issuer: a product
      // can be priced and advertised and still refuse at the checkout.
      { id: 'invoicing', label: 'Invoicing', to: '/console/invoicing', scope: 'platform', permission: 'staff.products.manage' },
      {
        // `platform-catalogue`, not `catalogue`: the tenant entry below already
        // uses that id, and ids stay unique across the whole tree so that a
        // highlighted entry is unambiguous.
        id: 'platform-catalogue',
        label: 'Catalogue',
        to: '/console/catalogue',
        scope: 'platform',
        permission: 'staff.catalog.manage',
      },
      // What a stranger sees. `staff.catalog.manage` rather than `catalog.manage`,
      // which ADR-040 lets the platform lend to a tenant — a tenant authoring its
      // own offers must not decide what the public page advertises.
      { id: 'storefront', label: 'Storefront', to: '/console/storefront', scope: 'platform', permission: 'staff.catalog.manage' },
      // After the chain, because it is not on it: what the shell shows each
      // kind of person. The one entry the setup cannot hide from the person
      // who holds it, or the choice could not be undone.
      { id: 'menus', label: 'Menus', to: '/console/menus', scope: 'platform', permission: 'staff.navigation.manage' },
    ],
  },
  {
    id: 'customers',
    label: 'Customers',
    entries: [
      { id: 'tenants', label: 'Tenants', to: '/console/tenants', scope: 'platform', permission: 'staff.tenants.read' },
      { id: 'threads', label: 'Conversations', to: '/console/conversations', scope: 'platform', permission: 'support.read' },
      { id: 'directory', label: 'Directory', to: '/console/directory', scope: 'platform', permission: 'admin.directory.read' },
      { id: 'metrics', label: 'Metrics', to: '/console/metrics', scope: 'platform', permission: 'admin.finance.read' },
    ],
  },
  {
    id: 'platform',
    label: 'Platform',
    entries: [
      // PLATFORM_ADMIN alone holds `staff.grant`: it is the one permission that
      // can turn any role into every role, by appointing somebody who holds it.
      { id: 'staff', label: 'Staff', to: '/console/staff', scope: 'platform', permission: 'staff.grant' },
      { id: 'queue', label: 'Queue', to: '/console/queue', scope: 'platform', permission: 'admin.health.read' },
      { id: 'audit', label: 'Audit', to: '/console/audit', scope: 'platform', permission: 'admin.audit.read', secondary: true },
      { id: 'access-log', label: 'Access log', to: '/console/access-log', scope: 'platform', permission: 'staff.access_log.read', secondary: true },
      // Its own entry and its own permission: reading the directory and erasing
      // somebody out of it are not the same authority.
      { id: 'erasure', label: 'Erasure', to: '/console/erasure', scope: 'platform', permission: 'admin.privacy.erase', secondary: true },
    ],
  },
  {
    id: 'work',
    label: 'Work',
    entries: [
      { id: 'projects', label: 'Projects', to: '/projects', scope: 'tenant', permission: 'projects.read' },
      { id: 'jobs', label: 'Jobs', to: '/jobs', scope: 'tenant', permission: 'jobs.read', secondary: true },
    ],
  },
  {
    id: 'commerce',
    label: 'Commerce',
    entries: [
      { id: 'catalogue', label: 'Catalogue', to: '/catalogue', scope: 'tenant', permission: 'catalog.read' },
      // Authoring is a different job from buying, and a different permission —
      // one ADR-040 makes a *lending*, so most tenants never see this.
      { id: 'offers', label: 'Offer authoring', to: '/offers', scope: 'tenant', permission: 'catalog.manage', secondary: true },
      { id: 'quotes', label: 'Quotes', to: '/quotes', scope: 'tenant', permission: 'sales.read', secondary: true },
      { id: 'orders', label: 'Orders', to: '/orders', scope: 'tenant', permission: 'sales.read', secondary: true },
    ],
  },
  {
    id: 'money',
    label: 'Money',
    entries: [
      { id: 'subscription', label: 'Subscription', to: '/subscription', scope: 'tenant', permission: 'subscription.read' },
      { id: 'invoices', label: 'Invoices', to: '/invoices', scope: 'tenant', permission: 'billing.read' },
      { id: 'payments', label: 'Payments', to: '/payments', scope: 'tenant', permission: 'payments.read', secondary: true },
      { id: 'credit-notes', label: 'Credit notes', to: '/credit-notes', scope: 'tenant', permission: 'billing.read', secondary: true },
      { id: 'billing-profile', label: 'Billing profile', to: '/billing-profile', scope: 'tenant', permission: 'billing.read', secondary: true },
      { id: 'tax', label: 'Tax profile', to: '/tax', scope: 'tenant', permission: 'tax.read', secondary: true },
      { id: 'tax-rates', label: 'Rates and regimes', to: '/tax/rates', scope: 'tenant', permission: 'tax.read', secondary: true },
      { id: 'tax-reports', label: 'VAT periods', to: '/tax/reports', scope: 'tenant', permission: 'tax.read', secondary: true },
    ],
  },
  {
    id: 'inbox',
    label: 'Inbox',
    entries: [
      { id: 'notifications', label: 'Notifications', to: '/notifications', scope: 'tenant', permission: 'notifications.read' },
      { id: 'conversations', label: 'Conversations', to: '/conversations', scope: 'tenant', permission: 'messages.read' },
    ],
  },
  {
    id: 'organisation',
    label: 'Organisation',
    entries: [
      // Administering the organisation and its people is the tenant
      // administrator's (2026-09-18): a USER may *read* both through the API,
      // but an entry to a screen they can only look at is noise on their menu.
      // The screens stay reachable by address for a reader.
      { id: 'organisation', label: 'Organisation', to: '/organisation', scope: 'tenant', permission: 'tenant.manage', secondary: true },
      { id: 'members', label: 'Members', to: '/members', scope: 'tenant', permission: 'members.manage', secondary: true },
      { id: 'profile', label: 'Your profile', to: '/profile', scope: 'tenant', permission: 'account.read', secondary: true },
      { id: 'branding', label: 'Branding', to: '/branding', scope: 'tenant', permission: 'skin.manage', secondary: true },
      { id: 'notification-settings', label: 'Notification settings', to: '/notification-settings', scope: 'tenant', permission: 'notifications.read', secondary: true },
    ],
  },
];

/**
 * Drops what this person may not reach, then what the platform's setup
 * leaves out for them, and then drops a section left empty.
 *
 * A section heading with nothing under it tells someone a capability exists and
 * that they do not have it, which is information the nav has no reason to
 * volunteer. The setup's rule comes second and never widens the first:
 * nothing hidden by permission is shown because a setup forgot it, and the
 * one entry that opens the setup itself is never hidden by it.
 */
export function visibleNav(
  sections: readonly NavSection[],
  authorities: Authorities,
  hidden: Hidden = {},
): readonly NavSection[] {
  return sections
    .map((section) => ({
      ...section,
      // `authorities[entry.scope]` and never a merged set: the entry names the
      // authority it answers to, so the wrong one is never consulted.
      entries: section.entries.filter(
        (entry) =>
          can(authorities[entry.scope], entry.permission) &&
          (entry.id === 'menus' || !(hidden[entry.scope] ?? NOTHING).has(entry.id)),
      ),
    }))
    .filter((section) => section.entries.length > 0);
}

/** The phone's bottom bar: at most five, primary entries first. */
export function bottomBarEntries(
  sections: readonly NavSection[],
  authorities: Authorities,
  limit = 5,
  hidden: Hidden = {},
): readonly NavEntry[] {
  const entries = visibleNav(sections, authorities, hidden).flatMap((section) => section.entries);

  return [
    ...entries.filter((entry) => entry.secondary !== true),
    ...entries.filter((entry) => entry.secondary === true),
  ].slice(0, limit);
}

/**
 * The sections that answer to the platform: the console's own menu (ADR-047).
 *
 * A section is the platform's when every entry in it is. Sections carry no
 * scope of their own — entries do — and a section mixing the two would be a
 * menu that says one thing under a heading that says another, so the filter
 * is strict rather than "any entry".
 */
export function platformSections(sections: readonly NavSection[]): readonly NavSection[] {
  return sections.filter(
    (section) =>
      section.entries.length > 0 && section.entries.every((entry) => entry.scope === 'platform'),
  );
}

/**
 * Where "the application" is for this person: the first tenant entry they may
 * open, or nowhere. The console's way back — a platform administrator who is
 * also a member of a tenant has an application to return to; one who is not
 * has only the front door.
 */
export function firstTenantEntry(sections: readonly NavSection[]): NavEntry | undefined {
  return sections
    .flatMap((section) => section.entries)
    .find((entry) => entry.scope === 'tenant');
}
