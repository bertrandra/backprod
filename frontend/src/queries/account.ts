import { useMutation, useQueryClient } from '@tanstack/react-query';

import { ambientParams } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError, type Session } from './session';

/**
 * Changing your own display name.
 *
 * `null` clears it and an absent field leaves it alone — the contract is explicit
 * about the difference, so this passes `null` deliberately rather than an empty
 * string, which would store a name that is one space long.
 */
export function useUpdateProfile() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (displayName: string | null): Promise<void> => {
      const { error, response } = await client.PATCH('/api/v1/me', {
        ...ambientParams(sessionSnapshot),
        body: { display_name: displayName },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: (_result, displayName) => {
      // The name appears in region A, so patching the cached session updates the
      // whole shell at once instead of leaving the header stale until a refetch.
      queryClient.setQueryData<Session>(keys.session.me, (previous) =>
        previous === undefined ? previous : { ...previous, displayName },
      );
    },
  });
}
