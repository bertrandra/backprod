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
  organisation: {
    current: ['organisation', 'current'] as const,
    usage: ['organisation', 'usage'] as const,
  },
  members: {
    all: ['members'] as const,
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
    list: (limit: number, offset: number) => ['projects', 'list', limit, offset] as const,
    one: (id: string) => ['projects', 'one', id] as const,
    versions: (id: string) => ['projects', 'one', id, 'versions'] as const,
    version: (projectId: string, versionId: string) =>
      ['projects', 'one', projectId, 'versions', versionId] as const,
    assets: (id: string) => ['projects', 'one', id, 'assets'] as const,
  },
  assets: {
    one: (id: string) => ['assets', id] as const,
  },
  subscription: {
    current: ['subscription', 'current'] as const,
    schedule: ['subscription', 'schedule'] as const,
    entitlements: ['subscription', 'entitlements'] as const,
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
