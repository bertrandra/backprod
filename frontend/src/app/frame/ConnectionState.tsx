import { useIsFetching, useIsMutating, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

import { NETWORK_UNREACHABLE } from '@/api/client';
import { ApiError } from '@/queries/session';

/**
 * Whether what is on screen can still be trusted.
 *
 * U9 asks for two things this answers together: *"what is shown when the API is
 * unreachable, and how stale data is marked as stale"*. Both are properties of
 * the whole page rather than of one query, so they belong in region A — one
 * indicator, in both shells, rather than a badge each screen remembers to add.
 *
 * Three states, and the order matters:
 *
 *   - **paused** — the browser says it is offline, so TanStack Query does not
 *     send the request at all: `networkMode: 'online'` **queues** it and runs it
 *     when the connection returns. Nothing failed and nothing will be lost,
 *     which is a different and much better thing to be told than "unreachable"
 *     — and it is what actually happens, discovered by watching a mutation sit
 *     pending for six seconds with no error while a test waited for one.
 *   - **unreachable** — a request *was* sent and did not arrive. That is the
 *     server being down rather than the machine being off the network, and the
 *     two want different words: one is worth retrying now, the other is worth
 *     waiting out. What is on screen is whatever was last loaded, and saying so
 *     is the point: an empty list during an outage and an empty list because
 *     there is nothing are the same picture, and `EmptyState` exists precisely
 *     because those must not look alike.
 *   - **updating** — a fetch is in flight over data already shown. Distinct from
 *     the skeleton a screen shows on first load: this is a refresh, and the
 *     figures under it are the previous answer until it lands.
 *   - **current** — nothing to say, so nothing is said. An indicator that is
 *     always visible is an indicator nobody reads.
 *
 * `navigator.onLine` is read as a *hint*, never as the answer: it reports
 * whether the machine has a network interface, not whether this API is
 * reachable, and it is famously true on a captive-portal wifi that answers
 * nothing. A failed request is the evidence; `onLine` only lets the wording say
 * "you are offline" rather than "the server is unreachable" when the browser
 * agrees.
 */
export type Connection = 'current' | 'updating' | 'paused' | 'unreachable';

/** Whether a failure was the network rather than an answer. */
function isUnreachable(error: unknown): boolean {
  return error instanceof ApiError && error.code === NETWORK_UNREACHABLE;
}

/**
 * The same, including an attempt that failed and is being retried.
 *
 * `error` is only set once retries are exhausted, which for the default policy
 * is three attempts — so a badge reading `error` alone stays silent through the
 * whole outage and appears once, at the end, when the person has already
 * decided the application is broken. `failureReason` carries the *last*
 * failure while retrying, which is when they need to be told.
 *
 * It also settles which of "updating" and "unreachable" is true during a retry:
 * both are, and retrying into a dead network is not "updating" in any sense a
 * person can use.
 */
function everUnreachable(state: { error: unknown; failureReason?: unknown }): boolean {
  return isUnreachable(state.error) || isUnreachable(state.failureReason);
}

export function useConnection(): { state: Connection; browserOffline: boolean } {
  const queryClient = useQueryClient();
  const fetching = useIsFetching();
  const mutating = useIsMutating();


  // Subscribed rather than polled: the cache tells us when a query's state
  // changes, and a timer would either lag behind an outage or spin for nothing.
  const [unreachable, setUnreachable] = useState(false);
  const [paused, setPaused] = useState(false);
  const [browserOffline, setBrowserOffline] = useState(false);

  useEffect(() => {
    // **Mutations count too**, and they are the case that matters most: a read
    // that fails leaves the last answer on screen, while a write that never
    // arrived leaves somebody wondering whether it went through. `onMutate` is
    // forbidden anywhere near money (`gate:money`), so nothing was optimistically
    // applied — but only the badge can say that the request never left.
    // Both states are read straight off the caches rather than through
    // `useIsFetching`/`useIsMutating` filters. Those combine a filter with their
    // own notion of "in flight", and asking them for *paused* returned an answer
    // that disagreed with the browser — which cost an afternoon and reads, in a
    // test, exactly like a missing feature.
    const read = () => {
      const queries = queryClient.getQueryCache().getAll();
      const mutations = queryClient.getMutationCache().getAll();

      setUnreachable(
        queries.some((query) => everUnreachable(query.state)) ||
          mutations.some((mutation) => everUnreachable(mutation.state)),
      );

      setPaused(
        queries.some((query) => query.state.fetchStatus === 'paused') ||
          mutations.some((mutation) => mutation.state.isPaused),
      );
    };

    read();

    const unsubscribeQueries = queryClient.getQueryCache().subscribe(read);
    const unsubscribeMutations = queryClient.getMutationCache().subscribe(read);

    return () => {
      unsubscribeQueries();
      unsubscribeMutations();
    };
  }, [queryClient]);

  useEffect(() => {
    const read = () => setBrowserOffline(!navigator.onLine);

    read();
    window.addEventListener('online', read);
    window.addEventListener('offline', read);

    return () => {
      window.removeEventListener('online', read);
      window.removeEventListener('offline', read);
    };
  }, []);

  // Order matters. A queued request is the strongest claim — it says nothing was
  // lost — and a retry in flight during an outage is still an outage, so neither
  // may be reported as "updating", which would suggest the figures are about to
  // be correct.
  return {
    state:
      paused
        ? 'paused'
        : unreachable
          ? 'unreachable'
          : fetching + mutating > 0
            ? 'updating'
            : 'current',
    browserOffline,
  };
}

export function ConnectionState() {
  const { state, browserOffline } = useConnection();

  if (state === 'current') {
    return null;
  }

  return (
    <span
      data-testid="connection-state"
      data-state={state}
      // Announced, because a person who cannot see the badge is the one most
      // affected by not knowing the figures are old. `polite` rather than
      // `assertive`: it interrupts nothing.
      role="status"
      aria-live="polite"
      className={`rounded px-2 py-0.5 text-xs font-medium ${
        state === 'unreachable'
          ? 'danger'
          : state === 'paused'
            ? 'warning'
            : 'neutral'
      }`}
    >
      {state === 'paused'
        ? 'Offline — this will be sent when you are back'
        : state === 'unreachable'
          ? browserOffline
            ? 'Offline — showing what was last loaded'
            : 'Server unreachable — showing what was last loaded'
          : 'Updating…'}
    </span>
  );
}
