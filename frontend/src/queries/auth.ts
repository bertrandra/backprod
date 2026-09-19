import { useMutation, useQueryClient } from '@tanstack/react-query';

import type { paths } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { withRoot } from '@/app/root';
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
 * What the server's message becomes on screen, for somebody creating an account.
 *
 * A different vocabulary from signing in, because the failures are different
 * ones: signing in refuses to say whether an address exists, and signing up
 * has to.
 */
function sayingForSignUp(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 409) {
      // Said plainly, unlike every message about an existing account
      // elsewhere. Somebody who cannot be told this cannot finish the
      // purchase they came for, and the sign-in form is one click away.
      return 'That address already has an account. Sign in instead.';
    }

    if (error.status === 403) {
      // The organisation's join policy, in its own words: by domain, or by
      // invitation only. Either way the person cannot get in from here.
      return error.code === 'JOIN_DOMAIN_NOT_ALLOWED'
        ? 'This organisation only admits addresses on its own domain. Use your work address, or ask an administrator to add you.'
        : 'This organisation adds people itself. Ask an administrator to add you.';
    }

    if (error.status === 404) {
      return 'No organisation lives at this address. Check the link you followed.';
    }

    if (error.status === 429) {
      return 'Too many attempts. Wait a minute and try again.';
    }

    if (error.status === 400 || error.status === 422) {
      return 'Check the form: an email address and a password of at least 12 characters.';
    }

    if (error.status === 503) {
      return 'This deployment cannot create accounts yet: it has no signing secret configured.';
    }
  }

  return 'Could not reach the sign-up service. Check your connection and try again.';
}

export class SignUpFailed extends Error {
  constructor(cause: unknown) {
    super(sayingForSignUp(cause));
    this.name = 'SignUpFailed';
  }
}

export interface NewAccount {
  readonly email: string;
  readonly password: string;
  /** The slug of the organisation at this root — the one being asked. */
  readonly tenant: string;
  /** The product the page was showing, if any: their default from then on. */
  readonly product?: string | null;
  readonly display_name?: string | null;
}

/** What a sign-up made: a session, and a membership that is live or waiting. */
export type CreatedAccount = paths['/api/v1/auth/sign-up']['post']['responses']['201']['content']['application/json'];

/**
 * A stranger asks to join the organisation at this root, and the token is
 * usable immediately (2026-09-17: a USER membership, live or pending —
 * never an organisation, never an administrator).
 *
 * **`grantToken`, not `signIn`.** The difference is the whole reason that
 * action exists: `SignInGate` renders the application the instant the status
 * flips, which would swap the storefront out from under the checkout call it
 * is about to make for the offer somebody just chose. The token becomes usable
 * now; the status follows when the page navigates, and the session is restored
 * from the refresh cookie on the other side like any other reload.
 *
 * Everything downstream is therefore an ordinary authenticated call — the
 * purchase that follows is not a second anonymous flow with rules of its own.
 */
export function useSignUp() {
  const client = useApiClient();
  const grantToken = useSessionStore((state) => state.grantToken);

  return useMutation({
    mutationFn: async (account: NewAccount) => {
      const { data, error, response } = await client.POST('/api/v1/auth/sign-up', {
        body: account,
      });

      if (error !== undefined || data === undefined) {
        throw new SignUpFailed(toApiError(response.status, error));
      }

      return data;
    },
    onSuccess: (session) => {
      grantToken({ accessToken: session.access_token, expiresIn: session.expires_in });
    },
  });
}

/**
 * Confirms an address from the token in the emailed link.
 *
 * It deliberately produces **no session**: the endpoint issues none, because a
 * link that did would be a credential sitting in an inbox for as long as that
 * mail is kept. Somebody who follows it confirms their address and then signs
 * in as themselves, which is the form already on the screen.
 *
 * Unknown, expired and already-used come back as one 400, so this says one
 * thing about all three rather than inventing distinctions the API withheld.
 */
export function useVerifyEmail() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (token: string) => {
      const { data, error, response } = await client.POST('/api/v1/auth/verify-email', {
        body: { token },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.verified;
    },
  });
}

/**
 * A forgotten password (2026-09-19): the address goes, and the answer is
 * the same whether or not it has an account — the server says so, and the
 * screen must say no more.
 */
export function useForgotPassword() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (email: string) => {
      const { data, error, response } = await client.POST('/api/v1/auth/password/forgot', {
        body: { email },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.accepted;
    },
  });
}

/**
 * The new password, from the link (2026-09-19). Signs nobody in: every
 * session of the account is revoked, and the person signs in afresh.
 */
export function useResetPassword() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (input: { token: string; password: string }) => {
      const { data, error, response } = await client.POST('/api/v1/auth/password/reset', {
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.reset;
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
      // Home, not the sign-in form for the page just left: the address is
      // moved before the session is forgotten, so the gate that renders next
      // sees the landing page and shows the storefront rather than a form
      // for a screen this person has just chosen to leave.
      window.history.replaceState(null, '', withRoot(useSessionStore.getState().root, '/'));
      forget();
      queryClient.clear();
    },
  });
}
