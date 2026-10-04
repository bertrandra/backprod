import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Palettes (2026-10-04).
 *
 * **The platform administrator's side** — no ambient product or tenant, like
 * every staff route — edits the palettes and assigns them from the matrix.
 * **The organisation's side** chooses among them for its own product, and
 * never edits one. Both write the same assignment, so after a write on either
 * side both caches are the server's to answer: nothing is adjusted locally.
 */
export type Palette = Schemas['Palette'];
export type PaletteAssignments = Schemas['PaletteAssignments'];

/** One page of the matrix. */
export const MATRIX_PAGE = 50;

// --- the platform administrator ---------------------------------------------

export function usePalettes() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.palettes,
    queryFn: async (): Promise<readonly Palette[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/palettes', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.palettes;
    },
  });
}

export function useSavePalette() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ name, document }: { name: string; document: Schemas['ThemeDocument'] }): Promise<Palette> => {
      const { data, error, response } = await client.PUT('/api/v1/staff/palettes/{name}', {
        params: { path: { name } },
        body: { document },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.palette;
    },
    // A first save adds a palette to the list and its order is the server's,
    // so the list is asked again rather than patched.
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: keys.staff.palettes }),
  });
}

export function usePaletteAssignments(offset: number) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.paletteAssignments(offset),
    queryFn: async (): Promise<PaletteAssignments> => {
      const { data, error, response } = await client.GET('/api/v1/staff/palette-assignments', {
        params: { query: { limit: MATRIX_PAGE, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAssignPalette() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ tenantId, productId, palette }: { tenantId: string; productId: string; palette: string | null }) => {
      const { data, error, response } = await client.PUT('/api/v1/staff/tenants/{tenantId}/products/{productId}/palette', {
        params: { path: { tenantId, productId } },
        body: { palette },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.palette;
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['staff', 'palettes', 'assignments'] }),
  });
}

// --- the organisation -------------------------------------------------------

export function useTenantPalettes() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.palettes.choice,
    queryFn: async (): Promise<{ readonly palettes: readonly Palette[]; readonly selected: string | null }> => {
      const { data, error, response } = await client.GET('/api/v1/tenant/palettes', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useSelectTenantPalette() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (palette: string | null): Promise<Palette | null> => {
      const { data, error, response } = await client.PUT('/api/v1/tenant/palette', {
        ...ambientParams(sessionSnapshot),
        body: { palette },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.palette;
    },
    // The response is what the screens now wear; the choice list's `selected`
    // is the server's to answer.
    onSuccess: (palette) => {
      queryClient.setQueryData(keys.palettes.worn, palette);
      void queryClient.invalidateQueries({ queryKey: keys.palettes.choice });
    },
  });
}

/**
 * The palette this organisation's screens wear, or null for the platform's.
 * Every member reads it; the shell paints with it.
 */
export function useWornPalette(enabled: boolean) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.palettes.worn,
    enabled,
    // A palette is decoration: failing to read it leaves the platform's
    // design on screen, which is a correct screen, rather than an error.
    retry: false,
    queryFn: async (): Promise<Palette | null> => {
      const { data, error, response } = await client.GET('/api/v1/tenant/palette', ambientParams(sessionSnapshot));

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.palette;
    },
  });
}
