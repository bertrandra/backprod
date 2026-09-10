import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { ambientParams } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

/**
 * Who is signed in, and what the shell may therefore offer.
 *
 * **`GET /me` is the only startup read.** It already returns the roles, the
 * permissions and the entitled capabilities, so calling `/me/permissions` and
 * `/me/entitlements` as well at boot would be three round trips for one
 * answer.
 *
 * Those two exist for **refresh**, and that is a real need rather than a way to
 * use up an endpoint: changing a member's role (U2) or a subscription's offer
 * (U6) changes what the shell should show, and re-reading the narrow answer is
 * cheaper than re-reading an identity that did not change. Both write into the
 * same cached session, so a caller never has to reconcile two sources.
 */

export const sessionKeys = {
  me: ['session', 'me'] as const,
  permissions: ['session', 'permissions'] as const,
  entitlements: ['session', 'entitlements'] as const,
};

export interface Session {
  readonly userId: string;
  readonly email: string | null;
  readonly displayName: string | null;
  readonly productId: string;
  readonly tenantId: string;
  readonly roles: readonly string[];
  readonly permissions: readonly string[];
  readonly capabilities: readonly string[];
}

/** Thrown when the API answers a failure. Carries the §10.4 envelope. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Readonly<Record<string, unknown>>,
    readonly requestId: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Reads the §10.4 envelope out of a failure, whatever produced it.
 *
 * Every failure has that shape — but a proxy timing out or a gateway returning
 * HTML does not, so the fallback matters: a screen must be able to say
 * something true when the body is not what the contract promised.
 */
export function toApiError(status: number, body: unknown): ApiError {
  const envelope =
    typeof body === 'object' && body !== null && 'error' in body ? body.error : null;

  if (typeof envelope === 'object' && envelope !== null) {
    const e = envelope as Record<string, unknown>;

    return new ApiError(
      status,
      typeof e.code === 'string' ? e.code : 'UNKNOWN',
      typeof e.message === 'string' ? e.message : 'The request failed.',
      typeof e.details === 'object' && e.details !== null
        ? (e.details as Record<string, unknown>)
        : {},
      typeof e.request_id === 'string' ? e.request_id : '',
    );
  }

  return new ApiError(
    status,
    'UNEXPECTED_RESPONSE',
    'The server answered in a shape this application does not recognise.',
    {},
    '',
  );
}

export function useSession() {
  const client = useApiClient();

  return useQuery({
    queryKey: sessionKeys.me,
    queryFn: async (): Promise<Session> => {
      const { data, error, response } = await client.GET('/api/v1/me', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return {
        userId: data.user_id,
        email: data.email ?? null,
        displayName: data.display_name ?? null,
        productId: data.product_id,
        tenantId: data.tenant_id,
        roles: data.roles,
        permissions: data.permissions,
        capabilities: data.capabilities,
      };
    },
    // A person's roles do not change while they read a page, and re-asking on
    // every mount would make the shell flicker. It is refreshed deliberately,
    // by the two functions below.
    staleTime: 5 * 60 * 1000,
    retry: (attempt, error) =>
      // 401 and 403 are answers, not failures: retrying cannot change them and
      // only delays telling the person what happened.
      !(error instanceof ApiError && (error.status === 401 || error.status === 403)) &&
      attempt < 2,
  });
}

/**
 * Re-reads permissions after something changed them, without re-reading
 * identity. Patches the cached session so every gate updates at once.
 */
export async function refreshPermissions(
  queryClient: QueryClient,
  read: () => Promise<{ roles: string[]; permissions: string[] }>,
): Promise<void> {
  const next = await read();

  queryClient.setQueryData<Session>(sessionKeys.me, (previous) =>
    previous === undefined
      ? previous
      : { ...previous, roles: next.roles, permissions: next.permissions },
  );
}

/** The same, for entitlements — which a subscription change moves. */
export async function refreshEntitlements(
  queryClient: QueryClient,
  read: () => Promise<{ capabilities: string[] }>,
): Promise<void> {
  const next = await read();

  queryClient.setQueryData<Session>(sessionKeys.me, (previous) =>
    previous === undefined ? previous : { ...previous, capabilities: next.capabilities },
  );
}

/**
 * The two narrow reads, bound to the client. Exposed as hooks so a screen that
 * changed a role or an offer can refresh what the shell offers.
 */
export function useSessionRefresh() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return {
    permissions: () =>
      refreshPermissions(queryClient, async () => {
        const { data, error, response } = await client.GET('/api/v1/me/permissions', ambientParams(sessionSnapshot));

        if (error !== undefined || data === undefined) {
          throw toApiError(response.status, error);
        }

        return { roles: data.roles, permissions: data.permissions };
      }),

    entitlements: () =>
      refreshEntitlements(queryClient, async () => {
        const { data, error, response } = await client.GET('/api/v1/me/entitlements', ambientParams(sessionSnapshot));

        if (error !== undefined || data === undefined) {
          throw toApiError(response.status, error);
        }

        return { capabilities: data.capabilities };
      }),
  };
}
