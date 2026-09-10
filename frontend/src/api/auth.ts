/**
 * The second door, and the only other one.
 *
 * `client.ts` says a single contract is defended by there being nowhere else to
 * write the call, and it is the somewhere for everything in `openapi.json`.
 * Supabase's token endpoint is not in `openapi.json` and never will be: it
 * belongs to the identity provider (ADR-014), which is why the backend verifies
 * a JWT rather than issuing one. So there has to be a second place, and this is
 * it — named, tiny, and the only other file ESLint permits `fetch` in.
 *
 * **Why not `@supabase/supabase-js`.** It would bring a session manager, a
 * storage layer and a realtime client to do one POST. The whole surface used
 * here is two grant types against one URL, and the SDK's own session handling
 * would then compete with `state/session.ts` over who owns the token.
 *
 * **What this deliberately does not do.** It never touches storage, never
 * schedules anything and holds no state: it exchanges credentials for a grant
 * and hands it back. Where the grant is kept and when it is renewed is
 * `state/session.ts`'s decision, and keeping the two apart is what makes both
 * testable without a browser.
 */

/** The public configuration a browser needs to reach the provider. */
export interface AuthConfig {
  readonly url: string;
  readonly anonKey: string;
}

/**
 * Both values are public by design — the anon key is shipped to every browser
 * and is safe there because it grants nothing on its own; row-level security
 * and this platform's own authorisation are what stand behind it. Neither is a
 * secret, and neither belongs in `.env` on the server: they are baked into the
 * bundle at build time because the bundle is what needs them.
 *
 * Absent, this returns null and the sign-in screen says so. That mirrors the
 * backend's own default: an empty `SUPABASE_JWKS` verifies no key and
 * authenticates nobody. A deployment that is not configured to sign anybody in
 * should say that plainly rather than fail on submit.
 */
export function authConfig(): AuthConfig | null {
  const url = import.meta.env.VITE_SUPABASE_URL;
  const anonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;

  if (url === undefined || url === '' || anonKey === undefined || anonKey === '') {
    return null;
  }

  /**
   * Only the origin, whatever was configured.
   *
   * The dashboard shows several URLs for one project and the REST endpoint is the
   * most prominent of them: `https://<ref>.supabase.co/rest/v1/`. Configured here,
   * it composes `https://<ref>.supabase.co/rest/v1/auth/v1/token` — which 404s, and
   * a 404 is not ok, so this file reports it as `credentials`. The person sees
   * "that email and password do not match an account" and goes looking for a
   * password problem that does not exist.
   *
   * That is a deployment mistake with a wrong error message, which is the worst
   * combination, and stripping a trailing slash was never enough to prevent it. So
   * the path goes too: every URL the dashboard offers for a project resolves to the
   * same origin, and this needs the origin.
   */
  try {
    return { url: new URL(url).origin, anonKey };
  } catch {
    // Not a URL at all. Null rather than a throw at module scope: the sign-in
    // screen then says the deployment has no identity provider, which is true and
    // is a sentence somebody can act on.
    return null;
  }
}

/**
 * What a successful exchange yields.
 *
 * `expiresAt` is computed from the provider's `expires_in` against the local
 * clock rather than read from its `expires_at`. The absolute timestamp is the
 * server's opinion of now, and a browser whose clock is ten minutes fast would
 * treat a fresh token as already expired. `expires_in` is a duration, so a
 * skewed clock measures it the same as an accurate one.
 */
export interface Grant {
  readonly accessToken: string;
  readonly refreshToken: string;
  readonly expiresAt: number;
}

/**
 * Why a sign-in did not happen.
 *
 * The provider's own message is deliberately not passed through. GoTrue's
 * wording varies by version, it is written for developers, and on some paths it
 * distinguishes "no such user" from "wrong password" — which is a disclosure
 * this screen should not make. Each kind maps to one sentence written here.
 */
export type AuthFailureKind =
  | 'unconfigured'
  | 'credentials'
  | 'rate_limited'
  | 'unreachable'
  | 'provider';

export class AuthFailure extends Error {
  constructor(
    readonly kind: AuthFailureKind,
    message: string,
  ) {
    super(message);
    this.name = 'AuthFailure';
  }
}

const MESSAGES: Record<AuthFailureKind, string> = {
  unconfigured: 'This deployment has no identity provider configured, so nobody can sign in yet.',
  credentials: 'That email and password do not match an account.',
  rate_limited: 'Too many attempts. Wait a minute and try again.',
  unreachable: 'Could not reach the sign-in service. Check your connection and try again.',
  provider: 'The sign-in service answered with an error. Try again in a moment.',
};

function failure(kind: AuthFailureKind): AuthFailure {
  return new AuthFailure(kind, MESSAGES[kind]);
}

/**
 * The provider's answer, narrowed.
 *
 * Parsed rather than cast. A 200 with a body this code did not expect is a
 * provider fault, not a token — and casting would produce a `Grant` whose
 * `accessToken` is `undefined`, which then fails later as an authorisation bug
 * somewhere else entirely.
 */
function grantFrom(body: unknown): Grant {
  if (typeof body !== 'object' || body === null) {
    throw failure('provider');
  }

  const record = body as Record<string, unknown>;
  const accessToken = record.access_token;
  const refreshToken = record.refresh_token;
  const expiresIn = record.expires_in;

  if (
    typeof accessToken !== 'string' ||
    accessToken === '' ||
    typeof refreshToken !== 'string' ||
    refreshToken === '' ||
    typeof expiresIn !== 'number' ||
    !Number.isFinite(expiresIn)
  ) {
    throw failure('provider');
  }

  return {
    accessToken,
    refreshToken,
    // Clamped: a provider that answers with a nonsensical lifetime should not
    // schedule a renewal in the past, which would spin.
    expiresAt: Date.now() + Math.max(expiresIn, 30) * 1000,
  };
}

async function exchange(grantType: 'password' | 'refresh_token', payload: object): Promise<Grant> {
  const config = authConfig();

  if (config === null) {
    throw failure('unconfigured');
  }

  let response: Response;

  try {
    response = await fetch(`${config.url}/auth/v1/token?grant_type=${grantType}`, {
      method: 'POST',
      headers: {
        apikey: config.anonKey,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });
  } catch {
    // A rejected fetch is an outage or a blocked request, never an answer. The
    // API client turns the same thing into a synthetic 503 so a screen sees one
    // failure shape (U9); here there is no envelope to imitate, so it becomes
    // the one kind that means "we never heard back".
    throw failure('unreachable');
  }

  if (!response.ok) {
    throw failure(
      response.status === 429
        ? 'rate_limited'
        : response.status >= 500
          ? 'provider'
          : 'credentials',
    );
  }

  let body: unknown;

  try {
    body = await response.json();
  } catch {
    throw failure('provider');
  }

  return grantFrom(body);
}

export function signInWithPassword(email: string, password: string): Promise<Grant> {
  return exchange('password', { email, password });
}

export function refreshGrant(refreshToken: string): Promise<Grant> {
  return exchange('refresh_token', { refresh_token: refreshToken });
}

/**
 * Revokes the refresh token at the provider, best effort.
 *
 * Signing out locally is the part that must always work, so this never throws
 * and its result is never awaited by anything that matters. But it is worth
 * attempting: a sign-out that only forgets the token locally leaves a valid
 * refresh token in existence, and "signed out" should mean the credential is
 * dead rather than merely mislaid.
 */
export async function revoke(accessToken: string): Promise<void> {
  const config = authConfig();

  if (config === null) {
    return;
  }

  try {
    await fetch(`${config.url}/auth/v1/logout`, {
      method: 'POST',
      headers: {
        apikey: config.anonKey,
        Authorization: `Bearer ${accessToken}`,
      },
    });
  } catch {
    // Nothing to say and nothing to do: the local session is gone either way.
  }
}
