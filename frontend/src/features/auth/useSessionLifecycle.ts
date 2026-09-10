import { useEffect } from 'react';

import { refreshGrant } from '@/api/auth';
import { rememberedRefreshToken, useSessionStore } from '@/state/session';

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
 * **Two effects rather than one**, because they answer different questions. The
 * first runs once: is there a refresh token from a previous visit, and does the
 * provider still honour it? The second runs whenever the token changes: when
 * should this one be renewed?
 *
 * This is a hook rather than logic inside the store deliberately. The store
 * holds what this browser is currently acting as and nothing else — no timers,
 * no requests — so it can be asserted in a test without a fake clock or a fake
 * network, and every existing test that pokes at it still works.
 */
export function useSessionLifecycle(): void {
  const status = useSessionStore((state) => state.status);
  const expiresAt = useSessionStore((state) => state.expiresAt);

  useEffect(() => {
    if (status !== 'restoring') {
      return;
    }

    const stored = rememberedRefreshToken();

    if (stored === null) {
      useSessionStore.getState().forget();

      return;
    }

    let cancelled = false;

    void (async () => {
      try {
        const grant = await refreshGrant(stored);

        if (!cancelled) {
          useSessionStore.getState().signIn(grant);
        }
      } catch {
        // Any failure lands on the sign-in form, and that is the right answer
        // for all of them. A refused token is gone; an unreachable provider
        // cannot be worked around by waiting inside a blank page; and either
        // way the person can retry from a screen that says something.
        if (!cancelled) {
          useSessionStore.getState().forget();
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [status]);

  useEffect(() => {
    if (status !== 'signed-in' || expiresAt === null) {
      return;
    }

    const stored = rememberedRefreshToken();

    if (stored === null) {
      // Storage is blocked, so there is nothing to renew with. The session lasts
      // as long as this token does, which is a fact rather than a fault.
      return;
    }

    // Never negative: a token that arrives already inside the margin is renewed
    // immediately rather than scheduled in the past.
    const delay = Math.max(expiresAt - Date.now() - RENEW_MARGIN_MS, 0);

    const timer = setTimeout(() => {
      void (async () => {
        try {
          useSessionStore.getState().signIn(await refreshGrant(stored));
        } catch {
          // One attempt. A retry loop against a provider that has refused the
          // token is noise, and the honest outcome is the sign-in form.
          useSessionStore.getState().forget();
        }
      })();
    }, delay);

    return () => {
      clearTimeout(timer);
    };
  }, [status, expiresAt]);
}
