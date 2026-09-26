import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Tenant = Schemas['Tenant'];

export function useOrganisation(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.organisation.current,
    enabled,
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

export type JoinPolicy = Tenant['join_policy'];

/**
 * Partial since 2026-09-17: the name, and how people arrive by themselves —
 * the join policy and, for DOMAIN, the domains. Each touched only when sent.
 */
export type OrganisationChange = {
  readonly name?: string;
  readonly join_policy?: JoinPolicy;
  readonly join_domains?: readonly string[];
  /**
   * Which product the organisation opens on (2026-09-26): a product code, or
   * `null` to clear it. Absent leaves it alone — which is why it is typed
   * `string | null` and read with `in`, not `=== undefined`: an explicit null
   * is a decision and has to travel.
   */
  readonly default_product?: string | null;
};

export function useUpdateOrganisation() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (change: OrganisationChange): Promise<Tenant> => {
      const { data, error, response } = await client.PATCH('/api/v1/tenants/current', {
        ...ambientParams(sessionSnapshot),
        body: {
          ...(change.name === undefined ? {} : { name: change.name }),
          ...(change.join_policy === undefined ? {} : { join_policy: change.join_policy }),
          ...(change.join_domains === undefined ? {} : { join_domains: [...change.join_domains] }),
          // `in`, not a null check: clearing the default is sending null, and
          // a check that treated null as "not asked" would make it unclearable.
          ...('default_product' in change ? { default_product: change.default_product } : {}),
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async (tenant) => {
      // Written straight into the cache rather than refetched: the API answered
      // with the row it wrote, so asking again would be a round trip to learn
      // what we were just told.
      queryClient.setQueryData(keys.organisation.current, tenant);
      // But the product a landing opens on rides on `GET /products`, which is
      // a different read and has just been made wrong (2026-09-26).
      await queryClient.invalidateQueries({ queryKey: keys.catalogue.myProducts });
    },
  });
}

/** Renaming alone — the older shape, kept for the callers that only rename. */
export function useRenameOrganisation() {
  const update = useUpdateOrganisation();

  return { ...update, mutate: (name: string, options?: Parameters<typeof update.mutate>[1]) => update.mutate({ name }, options) };
}