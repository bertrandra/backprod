import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, binaryBody, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Skin = Schemas['Skin'];

/** The types the API accepts for a logo, taken from the contract's own list. */
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const;
export type LogoType = (typeof LOGO_TYPES)[number];

export function isLogoType(value: string): value is LogoType {
  return (LOGO_TYPES as readonly string[]).includes(value);
}

/** Reading a skin needs neither the permission nor the entitlement (§3.4). */
export function useSkin() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.skin.current,
    queryFn: async (): Promise<Skin> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tenant/skin',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.skin;
    },
  });
}

export function useUpdateSkin() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      primary_color?: string | null;
      accent_color?: string | null;
    }): Promise<Skin> => {
      const { data, error, response } = await client.PATCH('/api/v1/tenant/skin', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.skin;
    },
    onSuccess: (skin) => queryClient.setQueryData(keys.skin.current, skin),
  });
}

/**
 * Uploading a logo.
 *
 * The body is the bytes and the content type is the real one, because the API
 * sniffs the bytes rather than trusting the header (ADR-028) — so sending
 * `application/octet-stream` to be safe would be refused, and sending a lie
 * would be caught. The file's own type is the honest thing to send.
 */
export function useUploadLogo() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File): Promise<void> => {
      if (!isLogoType(file.type)) {
        // Refused here as well as by the API. Not a substitute for the server's
        // check — the server sniffs the bytes and this only reads what the
        // browser guessed — but it turns the common mistake into an immediate
        // answer instead of an upload that fails after the wait.
        throw new Error(`A logo must be one of: ${LOGO_TYPES.join(', ')}.`);
      }

      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.POST('/api/v1/tenant/skin/logo', {
        ...ambient,
        ...binaryBody(file),
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.skin.current }),
  });
}

export function useDeleteLogo() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<void> => {
      const { error, response } = await client.DELETE(
        '/api/v1/tenant/skin/logo',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.skin.current }),
  });
}
