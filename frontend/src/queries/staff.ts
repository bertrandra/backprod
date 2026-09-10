import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The support desk, and the boundary it crosses.
 *
 * **No ambient headers anywhere in this file.** Every other query module sends
 * `X-Product` — and `X-Tenant` where a person belongs to more than one — because
 * a resource request without a product is meaningless (§12.1). A staff read is
 * not that kind of request: a platform role never grants tenant membership
 * (non-negotiable #22), so there is no ambient tenant to send, and the tenant is
 * named in the path instead. `ambientParams` would throw here for a staff member
 * with no product chosen, which is the right shape of failure for the tenant app
 * and simply wrong for this one.
 *
 * **Every read across the boundary is recorded.** The backend writes a
 * `StaffAccessEntry` naming who looked, at what, and *under which permission* —
 * the answer to "on what grounds?" that a log without it can never give. That
 * record is not optional and not something the screen arranges; the screen's job
 * is to make sure nobody is surprised by it, which is why the access log is a
 * screen of its own and why the reads that produce entries say so.
 */

export type Tenant = Schemas['Tenant'];
export type StaffAccessEntry = Schemas['StaffAccessEntry'];
export type Conversation = Schemas['Conversation'];
export type Message = Schemas['Message'];

export interface StaffIdentity {
  readonly userId: string;
  readonly roles: readonly string[];
  readonly permissions: readonly string[];
}

/**
 * Who is at the desk.
 *
 * The console's bootstrap read, and deliberately **not** `GET /me`: that answers
 * for a tenant member and carries a `tenant_id`, which a platform staff member
 * does not have. A console that read `/me` would either fail for real staff or
 * silently show one tenant's shell painted amber.
 *
 * There are no capabilities here, and that absence is the design: an entitlement
 * is a thing a tenant's plan grants, and the console is not inside anybody's
 * plan. `access` below fills the shape the shell's gates expect with an empty
 * capability list rather than inventing one.
 */
export function useStaffIdentity() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.identity,
    queryFn: async (): Promise<StaffIdentity> => {
      const { data, error, response } = await client.GET('/api/v1/staff/me', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return {
        userId: data.staff.user_id,
        roles: data.staff.roles,
        permissions: data.staff.permissions,
      };
    },
    staleTime: 5 * 60 * 1000,
    retry: (attempt, error) =>
      // 401 and 403 are answers. Somebody who is not staff will never become
      // staff by asking twice.
      !(error instanceof Error && 'status' in error && (error.status === 401 || error.status === 403)) &&
      attempt < 2,
  });
}

/**
 * The staff identity in the shape the shell's gates read.
 *
 * `can()` and `visibleNav()` take `{ permissions, capabilities }`, so this
 * adapts rather than duplicating them for the console — the two shells share no
 * navigation, but they do share the question "may this person see this", and
 * that question has one answer in this application.
 */
export function staffAccess(
  identity: StaffIdentity | undefined,
): { permissions: readonly string[]; capabilities: readonly string[] } | undefined {
  return identity === undefined
    ? undefined
    : { permissions: identity.permissions, capabilities: [] };
}

/** The tenants a support agent may look at. Reading this list is itself logged. */
export function useStaffTenants(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenants(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * One tenant, named in the path.
 *
 * The one place this platform lets a client name a tenant — ADR-015 forbids it
 * everywhere else, and this is not the exception it looks like: the path names
 * the tenant, the *platform role* authorises the read, and the read is recorded
 * either way.
 */
export function useStaffTenant(tenantId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenant(tenantId ?? ''),
    enabled: tenantId !== null,
    queryFn: async (): Promise<Tenant> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}', {
        params: { path: { tenantId: tenantId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
  });
}

/**
 * What staff looked at.
 *
 * The record that makes the rest of this shell acceptable. It is a first-class
 * screen rather than a debugging endpoint, because an audit nobody can read is
 * an audit nobody is accountable to.
 */
export function useAccessLog(limit = 50, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.accessLog(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/access-log', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useSupportConversations(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.conversations(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/conversations', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * One thread, with its messages and the tenant it belongs to.
 *
 * The tenant id comes back on the *detail* and not on the list, which is the
 * contract being careful: knowing which company is asking is already a crossing
 * of the boundary, and it happens when a thread is opened rather than when a
 * queue is skimmed.
 */
export function useSupportConversation(conversationId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.conversation(conversationId ?? ''),
    enabled: conversationId !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET(
        '/api/v1/staff/conversations/{conversationId}',
        { params: { path: { conversationId: conversationId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Answering.
 *
 * **Not optimistic, unlike the tenant's own reply in U3.** There the rule was
 * that optimism is safe where nothing binding is created, and a member's message
 * in their own thread qualifies. This does not: a support answer is written by
 * somebody acting with platform authority into a company's thread, it is logged
 * as such, and showing it as sent before the server took it would be showing an
 * official reply that may not exist.
 *
 * A closed thread answers 409, which is the reason the screen keeps `close` and
 * `reply` visibly apart rather than offering both to the last click.
 */
export function usePostSupportMessage(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body: string): Promise<Message> => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/conversations/{conversationId}/messages',
        { params: { path: { conversationId } }, body: { body } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.conversation(conversationId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationLists }),
      ]);
    },
  });
}

/** Closing a thread. Reopening is the tenant's to do by writing again. */
export function useCloseSupportConversation(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Conversation> => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/conversations/{conversationId}/close',
        { params: { path: { conversationId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.conversation(conversationId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationLists }),
      ]);
    },
  });
}
