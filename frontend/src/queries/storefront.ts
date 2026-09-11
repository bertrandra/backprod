import { useQuery } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The only reads in this application that work without a session.
 *
 * **No ambient headers, and none available.** Every other query module sends
 * `X-Product` because the context chain resolves a membership from it; there is
 * no membership here and no chain to resolve one, so the product travels as a
 * query parameter — the "filtre produit" a public page is built around.
 *
 * **What comes back is narrow by construction.** The backend returns only
 * offers somebody has marked as advertised *and* that are inside their sale
 * window, so this module needs no filtering of its own and must not add any:
 * a screen that decided what to hide would be a second opinion on a question
 * the platform already answered, and the two would drift.
 *
 * `product` is null whenever there is nothing to show — no such product, an
 * inactive one, or one that advertises nothing — because a public endpoint that
 * told those apart would be a way to enumerate what a deployment hosts.
 */

export type PublicOffer = Schemas['Offer'];

export interface Storefront {
  readonly product: { readonly code: string; readonly name: string } | null;
  readonly offers: readonly PublicOffer[];
}

export function useStorefront(productCode: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.window(productCode ?? ''),
    enabled: productCode !== null && productCode !== '',
    queryFn: async (): Promise<Storefront> => {
      const { data, error, response } = await client.GET('/api/v1/public/offers', {
        params: { query: { product: productCode ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return { product: data.product ?? null, offers: data.offers };
    },
    // A price changes when somebody publishes a version, not while a visitor
    // is reading the page.
    staleTime: 5 * 60 * 1000,
    // 401 cannot happen here and 429 will not improve by asking again
    // immediately, but a landing page is the worst place to give up on one
    // dropped connection.
    retry: 1,
  });
}

export function usePublicOffer(productCode: string | null, offerId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.offer(productCode ?? '', offerId ?? ''),
    enabled: productCode !== null && productCode !== '' && offerId !== null && offerId !== '',
    queryFn: async (): Promise<PublicOffer> => {
      const { data, error, response } = await client.GET('/api/v1/public/offers/{offerId}', {
        params: {
          path: { offerId: offerId ?? '' },
          query: { product: productCode ?? '' },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });
}
