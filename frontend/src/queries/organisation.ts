import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Tenant = Schemas['Tenant'];

export function useOrganisation() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.organisation.current,
    queryFn: async (): Promise<Tenant> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tenants/current',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
  });
}

/**
 * Usage against quota.
 *
 * The contract types each row as an open object, so this hands back what the API
 * sent rather than inventing a shape the contract does not promise. A screen
 * reading a field the API stopped sending is a bug worth having visible, not one
 * to paper over with a guessed interface.
 */
export function useTenantUsage() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.organisation.usage,
    queryFn: async (): Promise<readonly Record<string, unknown>[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tenants/current/usage',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.usage;
    },
  });
}

export function useRenameOrganisation() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (name: string): Promise<Tenant> => {
      const { data, error, response } = await client.PATCH('/api/v1/tenants/current', {
        ...ambientParams(sessionSnapshot),
        body: { name },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: (tenant) => {
      // Written straight into the cache rather than refetched: the API answered
      // with the row it wrote, so asking again would be a round trip to learn
      // what we were just told.
      queryClient.setQueryData(keys.organisation.current, tenant);
    },
  });
}
