import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The menu setup, two ways round.
 *
 * **What this person's menu leaves out** — `useMyNavigation` for a member,
 * `useStaffNavigation` for the console — is a bootstrap read beside
 * `/me/permissions`: the shell asks once per authority and hides the ids it
 * is told, beside what permissions already hide. The answer is resolved on
 * the server (the audience's setup, plus what is empty where that audience
 * asked), so there is one rule to apply here and no reason to know why an
 * entry is absent. A read that fails hides nothing: the menu is courtesy,
 * and a broken courtesy must not take the navigation with it.
 *
 * **The setup itself** — `useNavigationSetup` and `useSetNavigationSetup`
 * — is the console's, behind `staff.navigation.manage`. Replaced whole,
 * never patched, and the reply is the stored document, so the cache is
 * written from what the server kept rather than from what was sent.
 */

export type NavigationSetup = Schemas['NavigationSetup'];
export type AudienceMenu = Schemas['AudienceMenu'];
export type Audience = keyof NavigationSetup;

export const AUDIENCES: readonly Audience[] = ['platform_admin', 'tenant_admin', 'user'];

export function useMyNavigation(enabled: boolean) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.navigation.mine,
    enabled,
    queryFn: async (): Promise<readonly string[]> => {
      const { data, error, response } = await client.GET('/api/v1/me/navigation', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.hidden;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export function useStaffNavigation(enabled: boolean) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.navigation.staff,
    enabled,
    queryFn: async (): Promise<readonly string[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/me/navigation', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.hidden;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export function useNavigationSetup() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.navigation.setup,
    queryFn: async (): Promise<NavigationSetup> => {
      const { data, error, response } = await client.GET('/api/v1/staff/navigation', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.navigation;
    },
  });
}

export function useSetNavigationSetup() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (navigation: NavigationSetup): Promise<NavigationSetup> => {
      const { data, error, response } = await client.PUT('/api/v1/staff/navigation', { body: { navigation } });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.navigation;
    },
    onSuccess: async (stored) => {
      queryClient.setQueryData(keys.navigation.setup, stored);
      // The administrator's own menu may just have changed.
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.navigation.staff }),
        queryClient.invalidateQueries({ queryKey: keys.navigation.mine }),
      ]);
    },
  });
}
