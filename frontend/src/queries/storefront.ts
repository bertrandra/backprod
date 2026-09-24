import { useQuery } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { currentLocale } from '@/i18n';

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
 * One header does travel, and it is not ambient: `Accept-Language`
 * (2026-09-24), the language the reader has picked, sent explicitly and part
 * of the query key. It is a header rather than `?lang=` for the reason
 * ADR-050 removed that one — a parameter travels inside a shared link, and
 * would impose its author's language on whoever opened it next.
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
 *
 * **The product list is the list of shop windows, not of products.** What a
 * visitor may choose between is `listPublicProducts`: only products advertising
 * a sellable offer right now, which is exactly the set they could assemble by
 * trying codes one at a time. What the platform *runs* stays private (ADR-047,
 * amending ADR-041). It is never `listProducts` — that one answers from a
 * membership, and a stranger has none.
 */

export type PublicOffer = Schemas['Offer'];

export interface PublicProduct {
  readonly code: string;
  readonly name: string;
}

export interface Storefront {
  readonly product: PublicProduct | null;
  readonly offers: readonly PublicOffer[];
}

export type PublicTenant = Schemas['PublicTenant'];

/**
 * The organisation at this page's root (2026-09-17) — the one at the slug,
 * or the bare host's default. Not found is an answer here, not an error: it
 * means "no such organisation" for a slug and "the platform's own window"
 * for the bare host, and the screen says which.
 */
export function usePublicTenant(slug: string | null, enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.tenant(slug ?? ''),
    enabled,
    queryFn: async (): Promise<PublicTenant | null> => {
      const { data, error, response } = await client.GET('/api/v1/public/tenant', {
        params: { query: slug === null ? {} : { tenant: slug } },
      });

      if (response.status === 404) {
        return null;
      }

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export function usePublicProducts(tenant: string | null = null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.products(tenant ?? ''),
    queryFn: async (): Promise<readonly PublicProduct[]> => {
      const { data, error, response } = await client.GET('/api/v1/public/products', {
        params: { query: tenant === null ? {} : { tenant } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.products;
    },
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });
}

export function useStorefront(productCode: string | null, tenant: string | null = null) {
  const client = useApiClient();
  // Part of the key as well as of the request: switching language is a
  // different answer, not a stale one, so the window refetches instead of
  // sitting in English with French buttons around it.
  const locale = currentLocale();

  return useQuery({
    queryKey: keys.storefront.window(productCode ?? '', tenant ?? '', locale),
    enabled: productCode !== null && productCode !== '',
    queryFn: async (): Promise<Storefront> => {
      const { data, error, response } = await client.GET('/api/v1/public/offers', {
        params: {
          query: tenant === null ? { product: productCode ?? '' } : { product: productCode ?? '', tenant },
          header: { 'Accept-Language': locale },
        },
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
  const locale = currentLocale();

  return useQuery({
    queryKey: keys.storefront.offer(productCode ?? '', offerId ?? '', locale),
    enabled: productCode !== null && productCode !== '' && offerId !== null && offerId !== '',
    queryFn: async (): Promise<PublicOffer> => {
      const { data, error, response } = await client.GET('/api/v1/public/offers/{offerId}', {
        params: {
          path: { offerId: offerId ?? '' },
          query: { product: productCode ?? '' },
          header: { 'Accept-Language': locale },
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

export type DemoPage = Schemas['DemoPage'];

/**
 * The demonstration page (2026-09-18): what the platform hosts, who is in
 * it and as what — for anybody, while a platform administrator has the
 * switch on. Off is a 404, and the screen says so rather than erroring:
 * "there is no demonstration page" is an answer.
 */
export function usePublicDemo() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.demo,
    queryFn: async (): Promise<DemoPage | null> => {
      const { data, error, response } = await client.GET('/api/v1/public/demo', {});

      if (response.status === 404) {
        return null;
      }

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    staleTime: 0,
    retry: false,
  });
}