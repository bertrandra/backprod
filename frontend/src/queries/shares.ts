import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Share links on a project (ADR-073).
 *
 * **The URL exists once.** `createProjectShare` answers with it and nothing can
 * read it back — the server keeps only the token's hash. So the created link is
 * returned to the screen that asked, which shows it, and is never written into
 * the cache: a list that held it would keep a credential in memory for as long
 * as the tab lives, and would be the one place the "shown once" promise broke.
 *
 * **The status is the server's.** `ACTIVE`, `EXPIRED` and `REVOKED` are derived
 * on read against the server's clock, so every mutation invalidates the list
 * rather than editing a row here — a link revoked in this tab and the clock
 * passing an expiry are both things only the server answers.
 *
 * Nothing optimistic: making a link creates a credential somebody can act on.
 */

export type ProjectShare = Schemas['ProjectShare'];
export type CreatedProjectShare = Schemas['CreatedProjectShare'];

export interface NewProjectShare {
  readonly display_name: string | null;
  readonly recipient_label: string | null;
  readonly expires_in_days: number;
}

export function useProjectShares(projectId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.shares(projectId ?? ''),
    enabled: projectId !== null,
    queryFn: async (): Promise<readonly ProjectShare[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/projects/{projectId}/shares', {
        params: { ...ambient.params, path: { projectId: projectId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.shares;
    },
  });
}

export function useCreateProjectShare(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (share: NewProjectShare): Promise<CreatedProjectShare> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/projects/{projectId}/shares', {
        params: { ...ambient.params, path: { projectId } },
        body: share,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.projects.shares(projectId) }),
  });
}

export function useRevokeProjectShare(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (shareId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE('/api/v1/projects/{projectId}/shares/{shareId}', {
        params: { ...ambient.params, path: { projectId, shareId } },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.projects.shares(projectId) }),
  });
}
