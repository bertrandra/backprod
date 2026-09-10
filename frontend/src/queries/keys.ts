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
} as const;
