import { useMutation, useQueryClient } from '@tanstack/react-query';

import { ambientParams } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError, type Session } from './session';

/**
 * Changing your own profile: the display name, the default product.
 *
 * `null` clears a field and an absent one leaves it alone — the contract is
 * explicit about the difference, so a caller passes `null` deliberately rather
 * than an empty string, which would store a name that is one space long.
 */
export type ProfilePatch = {
  readonly display_name?: string | null;
  readonly default_product?: string | null;
};

export function useUpdateProfile() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (patch: ProfilePatch): Promise<ProfilePatch> => {
      const { data, error, response } = await client.PATCH('/api/v1/me', {
        ...ambientParams(sessionSnapshot),
        body: patch,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return { display_name: data.display_name ?? null, default_product: data.default_product ?? null };
    },
    onSuccess: async (stored) => {
      // The name appears in region A, so patching the cached session updates the
      // whole shell at once instead of leaving the header stale until a refetch.
      queryClient.setQueryData<Session>(keys.session.me, (previous) =>
        previous === undefined ? previous : { ...previous, displayName: stored.display_name ?? null },
      );
      // The default is read beside the product list, which now disagrees.
      await queryClient.invalidateQueries({ queryKey: keys.catalogue.myProducts });
    },
  });
}
