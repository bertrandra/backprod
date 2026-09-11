import { useState, type ReactNode } from 'react';

import { Storefront } from '@/features/storefront/Storefront';
import { useSessionStore } from '@/state/session';

import { SignInScreen } from './SignInScreen';
import { useSessionLifecycle } from './useSessionLifecycle';

/**
 * Nothing renders behind this without a token.
 *
 * Placed above the router rather than inside a shell, so both shells are behind
 * one gate: putting it in `TenantShell` would have left the console reachable by
 * URL with no session, and the two shells sharing a guard is exactly what
 * non-negotiable #22 does *not* forbid — they must not share navigation, which
 * is a different thing.
 *
 * **This is courtesy, not security.** Every request is authorised by the API
 * against the token it carries; a person who deleted this component from the
 * bundle would reach the same screens and be refused by the backend on every
 * one. The gate exists so that somebody who is not signed in is asked to,
 * instead of watching thirty screens each explain a 401 separately.
 *
 * **What a signed-out visitor gets depends on where they were going.** Asking
 * for the landing page is not asking to sign in — it is arriving — so that
 * gets the storefront, which is a page built for somebody with no account.
 * Asking for any other path is asking for something behind the gate, and that
 * gets the form. Signing in is therefore *secondary* on the way in and
 * immediate for anybody who follows a link, which is the weighting a product
 * that sells to strangers needs and the opposite of a login-first front door.
 *
 * The path is read from `window.location` rather than from the router,
 * because the router is what this gate decides whether to mount. It is read
 * once, and `wantsToSignIn` takes over from there: a visitor who clicks
 * "Sign in" is choosing, not navigating.
 */
export function SignInGate({ children }: { children: ReactNode }) {
  useSessionLifecycle();

  const status = useSessionStore((state) => state.status);
  const [wantsToSignIn, setWantsToSignIn] = useState(false);

  if (status === 'restoring') {
    // Deliberately not a spinner-free blank: a reload with a stored token spends
    // one round trip here, and an empty page for that long reads as broken.
    return (
      <main className="mx-auto flex min-h-dvh max-w-sm items-center justify-center p-4">
        <p role="status" aria-busy="true" className="text-sm text-neutral-600 dark:text-neutral-400">
          Restoring your session…
        </p>
      </main>
    );
  }

  if (status === 'signed-in') {
    return <>{children}</>;
  }

  const atLanding = window.location.pathname === '/' || window.location.pathname === '';

  return atLanding && !wantsToSignIn ? (
    <Storefront onSignIn={() => setWantsToSignIn(true)} />
  ) : (
    <SignInScreen />
  );
}
