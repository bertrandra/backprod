import type { ReactNode } from 'react';

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
 */
export function SignInGate({ children }: { children: ReactNode }) {
  useSessionLifecycle();

  const status = useSessionStore((state) => state.status);

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

  return status === 'signed-in' ? <>{children}</> : <SignInScreen />;
}
