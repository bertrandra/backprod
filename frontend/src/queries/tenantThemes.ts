import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * An organisation's themes (2026-10-04): the platform's five templates, the
 * organisation's own copies, and the one its members' screens wear.
 */
export type ThemeTemplate = Schemas['ThemeTemplate'];
export type TenantTheme = Schemas['TenantTheme'];
export type TenantThemeSummary = Schemas['TenantThemeSummary'];

export function useThemeTemplates() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.themes.templates,
    queryFn: async (): Promise<readonly ThemeTemplate[]> => {
      const { data, error, response } = await client.GET('/api/v1/tenant/theme-templates', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.templates;
    },
  });
}

export function useTenantThemes() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.themes.mine,
    queryFn: async (): Promise<readonly TenantThemeSummary[]> => {
      const { data, error, response } = await client.GET('/api/v1/tenant/themes', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.themes;
    },
  });
}

/** One of the organisation's themes, fetched when somebody opens it. */
export function useTenantTheme(name: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.themes.one(name ?? ''),
    enabled: name !== null,
    queryFn: async (): Promise<TenantTheme> => {
      const ambient = ambientParams(sessionSnapshot);
      const { data, error, response } = await client.GET('/api/v1/tenant/themes/{name}', {
        params: { ...ambient.params, path: { name: name ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
  });
}

/**
 * The theme this organisation's screens wear, or null for the platform's own.
 * Every member reads it; the shell paints with it.
 */
export function useActiveTenantTheme(enabled: boolean) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.themes.active,
    enabled,
    // A theme is decoration: a failure to read it leaves the platform's design
    // on screen, which is a correct screen, rather than an error over one.
    retry: false,
    queryFn: async (): Promise<TenantTheme | null> => {
      const { data, error, response } = await client.GET('/api/v1/tenant/theme', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
  });
}

export function useSaveTenantTheme() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ name, document }: { name: string; document: Schemas['ThemeDocument'] }): Promise<TenantTheme> => {
      const ambient = ambientParams(sessionSnapshot);
      const { data, error, response } = await client.PUT('/api/v1/tenant/themes/{name}', {
        params: { ...ambient.params, path: { name } },
        body: { document },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
    // The response is the theme; the list and the active one are the server's
    // to answer — a first save adds a row, and saving the active theme
    // changes what the screens wear.
    onSuccess: (theme) => {
      queryClient.setQueryData(keys.themes.one(theme.name), theme);
      void queryClient.invalidateQueries({ queryKey: keys.themes.mine, exact: true });
      void queryClient.invalidateQueries({ queryKey: keys.themes.active });
    },
  });
}

export function useSetActiveTenantTheme() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (name: string | null): Promise<TenantTheme | null> => {
      const { data, error, response } = await client.PUT('/api/v1/tenant/theme', {
        ...ambientParams(sessionSnapshot),
        body: { name },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.theme;
    },
    onSuccess: (theme) => {
      queryClient.setQueryData(keys.themes.active, theme);
      void queryClient.invalidateQueries({ queryKey: keys.themes.mine, exact: true });
    },
  });
}
