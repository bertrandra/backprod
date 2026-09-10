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
