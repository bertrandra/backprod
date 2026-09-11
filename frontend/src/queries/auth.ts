import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useApiClient } from '@/app/providers/ApiProvider';
import { useSessionStore } from '@/state/session';

import { ApiError, toApiError } from './session';

/**
 * Signing in, through the same door as everything else (U12).
 *
 * U11 had to open a second one: the token came from Supabase, whose endpoint is
 * not in `openapi.json` and never could be, so `api/auth.ts` existed as the only
 * other file ESLint permitted `fetch` in. U12 moved issuance into PHP, which means
 * these are ordinary contract operations and that file is **deleted**. The §8.1
 * rule is back to what it says: one module reaches the API, and there is nowhere
 * else to write a request.
 *
 * The refresh token is not here and cannot be — it lives in an `HttpOnly` cookie
 * the browser attaches by itself and no script can read. That is the point of it,
 * and it is why `refresh` and `signOut` take no argument.
 */

/** What the server's message becomes on screen. */
function saying(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401) {
      // Deliberately the same sentence for a wrong address and a wrong password.
      // The server refuses to tell them apart — that difference is how you learn
      // which addresses have accounts — and the screen should not invent a
      // distinction the API declined to make.
      return 'That email and password do not match an account.';
    }

    if (error.status === 429) {
      return 'Too many attempts. Wait a minute and try again.';
    }

    if (error.status === 503) {
      // A deployment fault, not a credential one, and worth saying plainly: no
      // amount of retrying or password-resetting will help.
      return 'This deployment cannot sign anybody in yet: it has no signing secret configured.';
    }

    if (error.status === 422) {
      return 'Enter an email address and a password of at least 12 characters.';
    }
  }

  return 'Could not reach the sign-in service. Check your connection and try again.';
}

export class SignInFailed extends Error {
  constructor(cause: unknown) {
    super(saying(cause));
    this.name = 'SignInFailed';
  }
}

export function useSignIn() {
  const client = useApiClient();
  const signIn = useSessionStore((state) => state.signIn);

  return useMutation({
    mutationFn: async (credentials: { email: string; password: string }) => {
      const { data, error, response } = await client.POST('/api/v1/auth/token', {
        body: credentials,
      });

      if (error !== undefined || data === undefined) {
        // Through `toApiError` first, so the §10.4 envelope is parsed the same way
        // every other call parses it — including the case where the body is not an
        // envelope at all, which is what a proxy timing out looks like.
        throw new SignInFailed(toApiError(response.status, error));
      }

      return data;
    },
    onSuccess: (session) => {
      signIn({ accessToken: session.access_token, expiresIn: session.expires_in });
    },
  });
}

/**
 * Ends the session, and empties the cache before the next person sees it.
 *
 * `clear()` rather than `invalidateQueries`: invalidation refetches, and
 * refetching after signing out is thirty requests that will all be refused. What
 * matters is that no answer belonging to the person who just left is still sitting
 * in the cache when somebody else signs in on the same browser.
 */
export function useSignOut() {
  const client = useApiClient();
  const queryClient = useQueryClient();
  const forget = useSessionStore((state) => state.forget);

  return useMutation({
    mutationFn: async () => {
      // The cookie is the credential and the browser sends it. A failure here is
      // not allowed to keep somebody signed in locally, so it is swallowed: the
      // server-side revoke is best effort, the local sign-out is not.
      await client.POST('/api/v1/auth/sign-out', {}).catch(() => undefined);
    },
    onSettled: () => {
      forget();
      queryClient.clear();
    },
  });
}
