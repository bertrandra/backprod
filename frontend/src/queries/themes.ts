import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The design system saved under a name (2026-10-04).
 *
 * A staff route, so no ambient product or tenant: a theme belongs to the
 * platform, like the mail templates. Stored, not applied — `index.css` is the
 * design system, and these are the records the console kept of it.
 */
export type ThemeDocument = Schemas['ThemeDocument'];
export type Theme = Schemas['Theme'];
export type ThemeSummary = Schemas['ThemeSummary'];

/** The name the console saves under unless told otherwise. */
export const DEFAULT_THEME = 'default';

export function useThemes() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.themes,
    queryFn: async (): Promise<readonly ThemeSummary[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/themes', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.themes;
    },
  });
}

/**
 * One saved theme, or `null` when nothing is saved under the name — which is
 * where `default` starts, and an answer rather than a failure.
 */
export function useTheme(name: string) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.theme(name),
    queryFn: async (): Promise<Theme | null> => {
      const { data, error, response } = await client.GET('/api/v1/staff/themes/{name}', {
        params: { path: { name } },
      });

      if (response.status === 404) {
        return null;
      }

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
  });
}

export function useSaveTheme() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ name, document }: { name: string; document: ThemeDocument }): Promise<Theme> => {
      const { data, error, response } = await client.PUT('/api/v1/staff/themes/{name}', {
        params: { path: { name } },
        body: { document },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
    // The response is the theme as it now stands, so it is written in; the
    // list is the server's to answer, because a first save adds a row to it.
    onSuccess: (theme) => {
      queryClient.setQueryData(keys.staff.theme(theme.name), theme);
      void queryClient.invalidateQueries({ queryKey: keys.staff.themes, exact: true });
    },
  });
}
