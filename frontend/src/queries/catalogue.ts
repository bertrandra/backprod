import { useQuery } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The catalogue: what exists to be sold, and how this product is configured.
 *
 * `commerce.catalogue` owns these endpoints. U4 read one of them early — a
 * project's `schema_version` must be one the *product* declares — and this file
 * is where that read now lives alongside the rest.
 *
 * **Nothing here branches on a product or a plan.** §6 of the spec and
 * `gate:products` / `gate:plans` forbid it in PHP, and non-negotiable #25 carries
 * it into the frontend: a plan is ordered by `rank`, never compared by name, and
 * a product is an id in a path. A `if (plan.code === 'PRO')` here would be the
 * same defect moved somewhere the backend gates cannot see it.
 *
 * The reads are cached longer than most: a catalogue changes when somebody
 * publishes an offer version, not while a person is reading it.
 */

export type ProductConfiguration = Schemas['ProductConfiguration'];

/** The configuration key the backend reads. Same key for every product. */
export const SCHEMA_VERSIONS_KEY = 'project_schema_versions';

export function useProductConfiguration(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.configuration(productId ?? ''),
    enabled: productId !== null,
    queryFn: async (): Promise<ProductConfiguration> => {
      // No ambient headers, and the compiler refuses them: the product is in the
      // path and this endpoint is not tenant-scoped. It describes the product
      // itself, which is the same answer for everyone who can see it.
      const { data, error, response } = await client.GET(
        '/api/v1/products/{productId}/configuration',
        { params: { path: { productId: productId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.configuration;
    },
  });
}

/**
 * The schema versions this product accepts, read defensively.
 *
 * The configuration is `additionalProperties: true` — free-form by contract —
 * so every step of the path is checked instead of assumed. An empty list is a
 * real answer and means "this product accepts no documents", which the create
 * form has to be able to say.
 */
export function supportedSchemaVersions(
  configuration: ProductConfiguration | undefined,
): readonly number[] {
  const entry = configuration?.[SCHEMA_VERSIONS_KEY];

  if (typeof entry !== 'object' || entry === null) {
    return [];
  }

  const supported = (entry as Record<string, unknown>).supported;

  if (!Array.isArray(supported)) {
    return [];
  }

  // Whole numbers only, matching the backend: a "2" or a 2.5 is a configuration
  // mistake there and treating it as 2 would hide it here too.
  return supported.filter((v): v is number => typeof v === 'number' && Number.isInteger(v) && v > 0);
}

/**
 * How long a catalogue answer stays fresh.
 *
 * Offers and plans change when somebody publishes a version, which is rare and
 * deliberate — refetching them on every screen mount would be asking a question
 * whose answer almost never differs. Five minutes is short enough that a
 * publication shows up on its own, and the authoring screen invalidates
 * explicitly rather than waiting.
 */
const CATALOGUE_STALE_MS = 5 * 60 * 1000;

export type Product = Schemas['Product'];
export type Plan = Schemas['Plan'];
export type Feature = Schemas['Feature'];
export type ProductFeature = Schemas['ProductFeature'];
export type Offer = Schemas['Offer'];
export type OfferVersion = Schemas['OfferVersion'];

/**
 * The products this person may act in — by membership, so never a platform
 * administrator's answer. `enabled` lets the switcher leave this alone on the
 * console, where the platform's own list is the one that applies (ADR-047).
 */
export function useProducts(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.products,
    enabled,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<readonly Product[]> => {
      const { data, error, response } = await client.GET('/api/v1/products', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.products;
    },
  });
}

export function useProduct(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.product(productId ?? ''),
    enabled: productId !== null,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<Product> => {
      const { data, error, response } = await client.GET('/api/v1/products/{productId}', {
        params: { path: { productId: productId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.product;
    },
  });
}

/**
 * The product, its features and its configuration in one read.
 *
 * Three things a screen needs together, so the contract offers them together —
 * one request rather than three that can arrive out of order and render a
 * half-built page in between.
 */
export function useProductCatalogue(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.productCatalogue(productId ?? ''),
    enabled: productId !== null,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/products/{productId}/catalog', {
        params: { path: { productId: productId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useProductFeatures(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.productFeatures(productId ?? ''),
    enabled: productId !== null,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<readonly ProductFeature[]> => {
      const { data, error, response } = await client.GET('/api/v1/products/{productId}/features', {
        params: { path: { productId: productId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.features;
    },
  });
}

/**
 * Plans, ordered by `rank`.
 *
 * **`rank` is the only ordering there is** (§13). An upgrade is a comparison of
 * two ranks, never of two names, so this sorts by it and no screen anywhere
 * decides that PRO comes after STARTER because of how the words look.
 */
export function usePlans() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.plans,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<readonly Plan[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/plans', ambient);

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return [...data.plans].sort((a, b) => a.rank - b.rank);
    },
  });
}

export function useFeatures() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.features,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<readonly Feature[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/features', ambient);

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.features;
    },
  });
}

/**
 * The offers on sale.
 *
 * Each carries the plan it belongs to and the version that prices it. `version`
 * is nullable in the contract — "null only in principle, but typed honestly
 * rather than asserted away" — so a screen has to be able to say that an offer
 * has nothing sellable rather than crash on its price.
 */
export function useOffers() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.offers,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<readonly Offer[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/offers', ambient);

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offers;
    },
  });
}

export function useOffer(offerId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.offer(offerId ?? ''),
    enabled: offerId !== null,
    staleTime: CATALOGUE_STALE_MS,
    queryFn: async (): Promise<Offer> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/offers/{offerId}', {
        params: { ...ambient.params, path: { offerId: offerId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}
