/**
 * Every query key in one place.
 *
 * A mutation has to invalidate by key, and a key spelled slightly differently at
 * the invalidation site silently invalidates nothing — the screen then shows
 * stale data and looks like a caching bug rather than a typo. Naming them here
 * makes that a compile error instead.
 */
export const keys = {
  session: {
    me: ['session', 'me'] as const,
  },
  /**
   * The shop window. Keyed by product code rather than product id, because
   * the caller has no session and therefore no resolved product — the code in
   * the URL is all there is.
   */
  storefront: {
    /** The public demonstration page (2026-09-18). */
    demo: ['storefront', 'demo'] as const,
    /** The organisation at a URL root; '' for the bare host. */
    tenant: (slug: string) => ['storefront', 'tenant', slug] as const,
    products: (tenant: string) => ['storefront', 'products', tenant] as const,
    window: (product: string, tenant: string) => ['storefront', 'offers', tenant, product] as const,
    offer: (product: string, offerId: string) =>
      ['storefront', 'offer', product, offerId] as const,
    listing: (product: string) => ['storefront', 'listing', product] as const,
  },
  organisation: {
    current: ['organisation', 'current'] as const,
    usage: ['organisation', 'usage'] as const,
  },
  members: {
    all: ['members'] as const,
    requests: ['members', 'requests'] as const,
  },
  skin: {
    current: ['skin', 'current'] as const,
  },
  notifications: {
    // `lists` is the prefix every paged list shares, so invalidating it catches
    // page two as well as page one. A key that only matched the current page
    // would leave a stale page behind for whoever scrolls back.
    lists: ['notifications', 'list'] as const,
    list: (limit: number, offset: number) => ['notifications', 'list', limit, offset] as const,
    unread: ['notifications', 'unread'] as const,
    deliveries: (id: string) => ['notifications', 'deliveries', id] as const,
    preferences: ['notifications', 'preferences'] as const,
    consents: ['notifications', 'consents'] as const,
  },
  catalogue: {
    products: ['catalogue', 'products'] as const,
    /** The same list with the person's default beside it — one read, two facts. */
    myProducts: ['catalogue', 'products', 'mine'] as const,
    product: (id: string) => ['catalogue', 'products', id] as const,
    productCatalogue: (id: string) => ['catalogue', 'products', id, 'catalog'] as const,
    productFeatures: (id: string) => ['catalogue', 'products', id, 'features'] as const,
    configuration: (id: string) => ['catalogue', 'products', id, 'configuration'] as const,
    plans: ['catalogue', 'plans'] as const,
    features: ['catalogue', 'features'] as const,
    offers: ['catalogue', 'offers'] as const,
    offer: (id: string) => ['catalogue', 'offers', id] as const,
    // Authoring reads the same offer with every version it has, drafts
    // included — a different answer from the sale view, so a different key.
    authored: (id: string) => ['catalogue', 'authoring', id] as const,
  },
  projects: {
    lists: ['projects', 'list'] as const,
    list: (limit: number, offset: number, deleted = false) =>
      ['projects', 'list', limit, offset, deleted] as const,
    one: (id: string) => ['projects', 'one', id] as const,
    versions: (id: string) => ['projects', 'one', id, 'versions'] as const,
    version: (projectId: string, versionId: string) =>
      ['projects', 'one', projectId, 'versions', versionId] as const,
    assets: (id: string) => ['projects', 'one', id, 'assets'] as const,
  },
  assets: {
    one: (id: string) => ['assets', id] as const,
  },
  tax: {
    profile: ['tax', 'profile'] as const,
    rates: (on: string) => ['tax', 'rates', on] as const,
    periods: ['tax', 'periods'] as const,
    period: (id: string) => ['tax', 'period', id] as const,
    transactions: (limit: number, offset: number) =>
      ['tax', 'transactions', limit, offset] as const,
  },
  subscription: {
    current: ['subscription', 'current'] as const,
    schedule: ['subscription', 'schedule'] as const,
    entitlements: ['subscription', 'entitlements'] as const,
    people: (seat: boolean) => ['subscription', 'people', seat ? 'seat' : 'tenant'] as const,
  },
  billing: {
    invoiceLists: ['billing', 'invoices'] as const,
    invoiceList: (limit: number, offset: number) => ['billing', 'invoices', limit, offset] as const,
    invoice: (id: string) => ['billing', 'invoice', id] as const,
    // Its own key, cached forever: the document is rendered once at issue and
    // its bytes never change (ADR-035).
    invoicePdf: (id: string) => ['billing', 'invoice', id, 'pdf'] as const,
    transmissions: (id: string) => ['billing', 'invoice', id, 'transmissions'] as const,
    paymentLists: ['billing', 'payments'] as const,
    paymentList: (limit: number, offset: number) => ['billing', 'payments', limit, offset] as const,
    payment: (id: string) => ['billing', 'payment', id] as const,
    creditNoteLists: ['billing', 'credit-notes'] as const,
    creditNoteList: (limit: number, offset: number) =>
      ['billing', 'credit-notes', limit, offset] as const,
    profile: ['billing', 'profile'] as const,
  },
  sales: {
    quoteLists: ['sales', 'quotes'] as const,
    quoteList: (limit: number, offset: number) => ['sales', 'quotes', limit, offset] as const,
    quote: (id: string) => ['sales', 'quote', id] as const,
    orderLists: ['sales', 'orders'] as const,
    orderList: (limit: number, offset: number) => ['sales', 'orders', limit, offset] as const,
    order: (id: string) => ['sales', 'order', id] as const,
  },
  checkout: {
    // Keyed by the session id, which *is* the order id (ADR-034). One key,
    // because there is one thing.
    session: (id: string) => ['checkout', id] as const,
  },
  jobs: {
    lists: ['jobs', 'list'] as const,
    list: (limit: number, offset: number) => ['jobs', 'list', limit, offset] as const,
    one: (id: string) => ['jobs', 'one', id] as const,
  },
  navigation: {
    // What this person's menu leaves out, one per authority — bootstrap
    // reads beside the session's, and keyed apart from it so a permissions
    // refresh does not drop them.
    mine: ['navigation', 'mine'] as const,
    staff: ['navigation', 'staff'] as const,
    /** The setup itself, every audience — one document, platform-wide. */
    setup: ['navigation', 'setup'] as const,
  },
  staff: {
    identity: ['staff', 'me'] as const,
    demoPage: ['staff', 'demo', 'page'] as const,
    storefrontSettings: ['staff', 'storefront', 'settings'] as const,
    tenantLists: ['staff', 'tenants'] as const,
    tenants: (limit: number, offset: number) => ['staff', 'tenants', limit, offset] as const,
    // The motive is part of a read's key, so a read made for one reason is never
    // served from the cache to a read made for another: an access this platform
    // records has to actually happen (R14). `*Reads` is the prefix an
    // invalidation uses, because it must match every motive.
    tenantReads: (id: string) => ['staff', 'tenant', id] as const,
    // What the platform gave on one product (2026-09-17): under the tenant's
    // prefix, so assigning or withdrawing a product refreshes it too.
    tenantEntitlement: (id: string, productId: string) => ['staff', 'tenant', id, 'entitlement', productId] as const,
    tenant: (id: string, purpose: string, reference: string) =>
      ['staff', 'tenant', id, purpose, reference] as const,
    tenantMembers: (id: string, product: string, purpose: string, reference: string) =>
      ['staff', 'tenant', id, 'members', product, purpose, reference] as const,
    // One read per tab of the customer workspace; `what` names the tab.
    tenantRead: (id: string, what: string, product: string, purpose: string, reference: string) =>
      ['staff', 'tenant', id, what, product, purpose, reference] as const,
    tenantConversations: (id: string) => ['staff', 'conversations', 'tenant', id] as const,
    accessLog: (limit: number, offset: number) => ['staff', 'access-log', limit, offset] as const,
    // No arguments, because the roster is unpaged: platform staff is a handful
    // of people, and a list that needed pages would be the finding rather than
    // the feature.
    roster: ['staff', 'members'] as const,
    // Unpaged like the roster: a platform hosts a handful of products, and a
    // cursor over five rows is machinery nobody needs.
    products: ['staff', 'products'] as const,
    /** Plans and features of one product, which an offer is built out of. */
    catalogue: (product: string) => ['staff', 'catalogue', product] as const,
    /**
     * What one product needs configured before it can take money: the identity
     * its invoices name, and the supplier's own fiscal position.
     */
    configuration: (product: string) => ['staff', 'configuration', product] as const,
    /**
     * What a product still needs before it can sell. Invalidated by every write
     * that could advance the chain, because the whole point is that it is
     * current — a stale chain tells somebody to do what they just did.
     */
    readiness: (product: string) => ['staff', 'readiness', product] as const,
    conversationLists: ['staff', 'conversations'] as const,
    conversations: (limit: number, offset: number) =>
      ['staff', 'conversations', limit, offset] as const,
    conversationReads: (id: string) => ['staff', 'conversation', id] as const,
    conversation: (id: string, purpose: string, reference: string) =>
      ['staff', 'conversation', id, purpose, reference] as const,
  },
  admin: {
    metrics: (productId: string, months: number, month: string) =>
      ['admin', 'metrics', productId, months, month] as const,
    queue: (staleAfterSeconds: number) => ['admin', 'queue', staleAfterSeconds] as const,
    jobs: (status: string, type: string, limit: number, offset: number) =>
      ['admin', 'jobs', status, type, limit, offset] as const,
    // The shared prefix, so an erasure invalidates every directory listing and
    // every page of each — the user's row changes, and so do the counts beside
    // the tenants they belonged to.
    directories: ['admin', 'directory'] as const,
    directory: (which: string, filter: string, limit: number, offset: number) =>
      ['admin', 'directory', which, filter, limit, offset] as const,
    // One customer's rows, as the console's tenant workspace reads them.
    tenantDirectory: (which: string, tenantId: string, productId: string, limit: number, offset: number) =>
      ['admin', 'directory', which, 'tenant', tenantId, productId, limit, offset] as const,
    audits: ['admin', 'audit'] as const,
    audit: (limit: number, offset: number) => ['admin', 'audit', limit, offset] as const,
  },
  conversations: {
    // Same shape as `notifications` above, and for the same reason: `lists` is
    // the shared prefix so a mutation invalidates every page, while a thread and
    // its messages are keyed by id and are not swept away with the list.
    lists: ['conversations', 'list'] as const,
    list: (limit: number, offset: number) => ['conversations', 'list', limit, offset] as const,
    one: (id: string) => ['conversations', 'thread', id] as const,
    messages: (id: string) => ['conversations', 'thread', id, 'messages'] as const,
  },
} as const;
