import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, binaryBody, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Tenant = Schemas['Tenant'];
export type QuotaUsage = Schemas['QuotaUsage'];

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
 * This docblock used to explain that the contract typed each row as an open
 * object, so the hook handed back what the API sent rather than inventing a
 * shape — reasonable, and it hid the actual problem. `additionalProperties:
 * true` is not a shape the contract declines to promise; it is a shape nobody
 * wrote down. The server had been answering eight named fields since M4, the
 * same eight the bootstrap a product reads already typed in full, and a screen
 * could read none of them because the generated client said `unknown`. A
 * missing field is a field missing **from OpenAPI** (CLAUDE.md): the answer was
 * to fix the contract, which `QuotaUsage` now does for both operations at once.
 *
 * `enabled` is the caller's: the workspace asks for this to draw one meter, and
 * on a product whose offer sells no quota there is nothing to ask about.
 */
export function useTenantUsage(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.organisation.usage,
    enabled,
    queryFn: async (): Promise<readonly QuotaUsage[]> => {
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
/**
 * The organisation's logo (2026-10-05): one for the organisation, in every
 * product, set by its administrator and tied to no offer.
 *
 * The types the API accepts, from the contract's own list. SVG is
 * deliberately absent — ADR-028 refuses it for the stored-scripting reason.
 */
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const;
export type LogoType = (typeof LOGO_TYPES)[number];

export function isLogoType(value: string): value is LogoType {
  return (LOGO_TYPES as readonly string[]).includes(value);
}

/**
 * Uploading the logo.
 *
 * The body is the bytes and the content type is the real one, because the API
 * sniffs the bytes rather than trusting the header (ADR-028). The answer is the
 * organisation as it now is, so it is written into the cache.
 */
export function useUploadOrganisationLogo() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File): Promise<Tenant> => {
      if (!isLogoType(file.type)) {
        // Refused here as well as by the API — not a substitute for the
        // server's check, but it turns the common mistake into an immediate
        // answer instead of an upload that fails after the wait.
        throw new Error(`A logo must be one of: ${LOGO_TYPES.join(', ')}.`);
      }

      const { data, error, response } = await client.POST('/api/v1/tenants/current/logo', {
        ...ambientParams(sessionSnapshot),
        ...binaryBody(file),
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: (tenant) => queryClient.setQueryData(keys.organisation.current, tenant),
  });
}

/** The organisation stops showing a logo; the file stays in its assets. */
export function useRemoveOrganisationLogo() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Tenant> => {
      const { data, error, response } = await client.DELETE(
        '/api/v1/tenants/current/logo',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: (tenant) => queryClient.setQueryData(keys.organisation.current, tenant),
  });
}
