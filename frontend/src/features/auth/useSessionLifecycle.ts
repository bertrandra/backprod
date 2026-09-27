import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';

import { renewalOf } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { useSessionStore } from '@/state/session';

/**
 * How long before expiry a renewal is attempted.
 *
 * Sixty seconds, so a request already in flight when the timer fires still has a
 * token the API will accept. Renewing exactly at expiry would leave a window in
 * which a slow request is authorised on the way out and rejected on arrival.
 */
const RENEW_MARGIN_MS = 60_000;

/**
 * How long to wait before asking again when the server could not answer —
 * rate-limited, failing or unreachable (ADR-062). Growing, and capped, so a
 * long outage is not met with a request a second from every open tab.
 */
const RETRY_DELAYS_MS = [5_000, 15_000, 30_000, 60_000];

function retryDelay(attempt: number): number {
  return RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)] ?? 60_000;
}

/**
 * Keeps a signed-in session signed in, recovers one across a reload, and keeps
 * every tab of this browser in step (ADR-062).
 *
 * **Every renewal goes through one door** — `renewalOf(client)`, the same
 * instance the request middleware retries 401s through — which runs one
 * refresh at a time across every tab and tells the others what happened. React's
 * StrictMode double mount, two tabs restored together, a timer and a 401 firing
 * in the same second after sleep: each used to be its own request with the same
 * cookie, and each is now one.
 *
 * **Only a refusal signs anybody out.** Rate-limited, failing or unreachable,
 * the session is kept and asked about again shortly. On a reload that means
 * `unreachable` rather than the sign-in form: the cookie may be fine, and
 * asking somebody to sign in because their Wi-Fi was slow to wake throws away
 * a session that was.
 */
export function useSessionLifecycle(): void {
  const client = useApiClient();
  const queryClient = useQueryClient();
  const status = useSessionStore((state) => state.status);
  const expiresAt = useSessionStore((state) => state.expiresAt);

  // What other tabs did. A renewed token is taken as this tab's own — the
  // cookie behind it is shared anyway — and an ended session ends here too,
  // cache and all, so nothing belonging to it is left on screen.
  useEffect(
    () =>
      renewalOf(client).subscribe((message) => {
        const current = useSessionStore.getState().status;

        if (message.kind === 'renewed') {
          // Not into a tab that is signed out: it may be half-way through
          // creating an account, and swapping the page from under it would
          // lose the checkout that follows. It resumes on its next load.
          if (current !== 'anonymous') {
            useSessionStore.getState().signIn({
              accessToken: message.accessToken,
              expiresIn: Math.max(0, Math.floor((message.expiresAt - Date.now()) / 1000)),
            });
          }

          return;
        }

        if (current === 'signed-in') {
          useSessionStore.getState().forget();
          queryClient.clear();
        }
      }),
    [client, queryClient],
  );

  // Is there a session to resume? Asked on load, and again from `unreachable`.
  useEffect(() => {
    if (status !== 'restoring') {
      return;
    }

    let cancelled = false;

    void (async () => {
      const outcome = await renewalOf(client).renew();

      if (cancelled) {
        return;
      }

      if (outcome.kind === 'renewed') {
        useSessionStore.getState().signIn(outcome.grant);
      } else if (outcome.kind === 'refused') {
        // No cookie, or one the server has ended: the honest answer is the form.
        useSessionStore.getState().forget();
      } else {
        useSessionStore.getState().unreachable();
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [status, client]);

  // Unreachable: ask again after a while, and at once when the browser says
  // the network is back.
  useEffect(() => {
    if (status !== 'unreachable') {
      return;
    }

    const retry = () => useSessionStore.getState().retryRestore();
    const timer = setTimeout(retry, retryDelay(0));

    window.addEventListener('online', retry);

    return () => {
      clearTimeout(timer);
      window.removeEventListener('online', retry);
    };
  }, [status]);

  // When should this token be renewed?
  useEffect(() => {
    if (status !== 'signed-in' || expiresAt === null) {
      return;
    }

    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const attempt = (failures: number) => {
      void (async () => {
        const outcome = await renewalOf(client).renew();

        if (cancelled) {
          return;
        }

        if (outcome.kind === 'renewed') {
          // A new `expiresAt`, which re-runs this effect and schedules the next.
          useSessionStore.getState().signIn(outcome.grant);
        } else if (outcome.kind === 'refused') {
          useSessionStore.getState().forget();
          queryClient.clear();
        } else {
          // The session stands. Requests made meanwhile may be answered 401
          // once the access token lapses; the middleware asks through the same
          // door, and whichever attempt succeeds first renews every tab.
          timer = setTimeout(() => attempt(failures + 1), retryDelay(failures));
        }
      })();
    };

    // Never negative: a token that arrives already inside the margin is renewed
    // immediately rather than scheduled in the past.
    timer = setTimeout(() => attempt(0), Math.max(expiresAt - Date.now() - RENEW_MARGIN_MS, 0));

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [status, expiresAt, client, queryClient]);
}
