import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Member = Schemas['Member'];

export function useMembers(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.members.all,
    enabled,
    queryFn: async (): Promise<readonly Member[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tenants/current/members',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.members;
    },
  });
}

export function useAddMember() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { email: string; roles: string[] }): Promise<void> => {
      const { error, response } = await client.POST('/api/v1/tenants/current/members', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    // Invalidated rather than patched: the API assigns the user id, and guessing
    // a row to insert would mean inventing the one field only the server knows.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.members.all }),
  });
}

export function useUpdateMemberRoles() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { userId: string; roles: string[] }): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.PATCH('/api/v1/tenants/current/members/{userId}', {
        params: { ...ambient.params, path: { userId: input.userId } },
        body: { roles: input.roles },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.members.all });
      // Changing your own roles changes what the shell may offer, which is what
      // /me/permissions exists for. Refetched rather than assumed, because the
      // backend decides what a role grants.
      await queryClient.invalidateQueries({ queryKey: keys.session.me });
    },
  });
}

export function useRemoveMember() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE('/api/v1/tenants/current/members/{userId}', {
        params: { ...ambient.params, path: { userId } },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.members.all }),
  });
}

/**
 * Who asked to join by themselves and is waiting (2026-09-17).
 *
 * A separate list from the members, because a pending membership grants
 * nothing and `listMembers` says so by leaving it out. `members.read`, like
 * the members; deciding is `members.manage`.
 */
export function useJoinRequests(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.members.requests,
    enabled,
    queryFn: async (): Promise<readonly Member[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tenants/current/members/requests',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.requests;
    },
  });
}

/**
 * Letting somebody in, or turning them away. Both invalidate rather than
 * patch: accepting moves a person from one list to the other, and which
 * roles they arrive with is the server's to say.
 */
export function useDecideJoinRequest() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { userId: string; decision: 'accept' | 'decline' }): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);
      const params = { ...ambient.params, path: { userId: input.userId } };

      const { error, response } =
        input.decision === 'accept'
          ? await client.POST('/api/v1/tenants/current/members/{userId}/accept', { params })
          : await client.POST('/api/v1/tenants/current/members/{userId}/decline', { params });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.members.requests });
      await queryClient.invalidateQueries({ queryKey: keys.members.all });
    },
  });
}