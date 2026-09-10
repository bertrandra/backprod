import { useQuery } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * What this product is configured to accept.
 *
 * The area that *owns* the product endpoints is `commerce.catalogue` (U5). This
 * reads one of them for one field, because the alternative is worse: creating a
 * project requires a `schema_version`, the backend accepts only the versions the
 * **product** declares (`SchemaVersionPolicy`), and a frontend that sent `1`
 * would be hard-coding a fact about one product into a shared client — the
 * frontend form of what `gate:products` forbids in PHP (UR5).
 *
 * A product that has declared nothing supports nothing, and its projects cannot
 * be written. That is deliberate on the backend and it is honoured here: the
 * form says so rather than offering a control that would always be refused.
 */

export type ProductConfiguration = Schemas['ProductConfiguration'];

/** The configuration key the backend reads. Same key for every product. */
export const SCHEMA_VERSIONS_KEY = 'project_schema_versions';

export function useProductConfiguration(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.products.configuration(productId ?? ''),
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
