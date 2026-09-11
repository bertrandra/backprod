import { useEffect } from 'react';

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
 * Keeps a signed-in session signed in, and recovers one across a reload.
 *
 * **This hook no longer knows whether there is anything to recover**, and that is
 * the U12 change. It used to read a refresh token out of `localStorage` and decide;
 * now it simply asks `/api/v1/auth/refresh`, and the browser attaches an
 * `HttpOnly` cookie that this code cannot see. A 401 means "not signed in", which
 * is an answer rather than a guess — and one that cannot be wrong because storage
 * was cleared, blocked, or read from the wrong key.
 *
 * Two effects, because they answer different questions. The first runs once: is
 * there a session to resume? The second runs whenever the token changes: when
 * should this one be renewed?
 */
export function useSessionLifecycle(): void {
  const client = useApiClient();
  const status = useSessionStore((state) => state.status);
  const expiresAt = useSessionStore((state) => state.expiresAt);

  useEffect(() => {
    if (status !== 'restoring') {
      return;
    }

    let cancelled = false;

    void (async () => {
      const { data } = await client.POST('/api/v1/auth/refresh', {});

      if (cancelled) {
        return;
      }

      if (data === undefined) {
        // Any failure lands on the sign-in form, and that is right for all of
        // them: no cookie, a revoked one, or a provider that cannot be reached.
        // Waiting inside a blank page fixes none of those, and a form at least
        // says something.
        useSessionStore.getState().forget();

        return;
      }

      useSessionStore.getState().signIn({
        accessToken: data.access_token,
        expiresIn: data.expires_in,
      });
    })();

    return () => {
      cancelled = true;
    };
  }, [status, client]);

  useEffect(() => {
    if (status !== 'signed-in' || expiresAt === null) {
      return;
    }

    // Never negative: a token that arrives already inside the margin is renewed
    // immediately rather than scheduled in the past.
    const delay = Math.max(expiresAt - Date.now() - RENEW_MARGIN_MS, 0);

    const timer = setTimeout(() => {
      void (async () => {
        const { data } = await client.POST('/api/v1/auth/refresh', {});

        if (data === undefined) {
          // One attempt. Retrying against a server that has refused the cookie is
          // noise, and the honest outcome is the sign-in form.
          useSessionStore.getState().forget();

          return;
        }

        useSessionStore.getState().signIn({
          accessToken: data.access_token,
          expiresIn: data.expires_in,
        });
      })();
    }, delay);

    return () => {
      clearTimeout(timer);
    };
  }, [status, expiresAt, client]);
}
