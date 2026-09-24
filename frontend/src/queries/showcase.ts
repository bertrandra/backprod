import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { currentLocale } from '@/i18n';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The story a product tells on its own page (2026-09-24,
 * `docs/home-showcase-spec.md`).
 *
 * Read twice, by two different callers, and the difference is the whole
 * shape of this module:
 *
 * - **a stranger** reads one published product's story by its *code*, with
 *   no session and no product context — the same position the storefront
 *   is in, and the same reason nothing here sends `X-Product`;
 * - **the console** reads every band with every language beside it,
 *   because it is the only screen that can finish a half-translated page.
 */

export type Showcase = Schemas['Showcase'];
export type ShowcaseBlock = Schemas['EditableShowcaseBlock'];
export type ShowcaseBlockInput = Schemas['ShowcaseBlockInput'];

/**
 * One published product's story, to anybody.
 *
 * A 404 is an **answer**, not an error: it means "nothing published here",
 * which the page renders as the product's name and its prices. Erroring
 * would turn a product that has not written its story yet into a broken
 * shop window.
 */
export function usePublicShowcase(productCode: string | null) {
  const client = useApiClient();
  // Read at call time and **part of the key**, so switching language
  // refetches instead of showing the previous one's story until something
  // else invalidates it. `currentLocale()` is the language the reader is
  // actually reading in, which is what the picker on the storefront sets.
  const locale = currentLocale();

  return useQuery({
    queryKey: keys.showcase.public(productCode ?? '', locale),
    enabled: productCode !== null && productCode !== '',
    queryFn: async (): Promise<Showcase | null> => {
      const { data, error, response } = await client.GET(
        '/api/v1/public/products/{code}/showcase',
        {
          params: {
            path: { code: productCode ?? '' },
            // A header and not `?lang=`, which ADR-050 removed: a query
            // parameter travels in a link, so a shared page could impose a
            // language on whoever opened it next. A header cannot.
            header: { 'Accept-Language': locale },
          },
        },
      );

      if (response.status === 404) {
        return null;
      }

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.showcase;
    },
    // A story changes when somebody publishes one, not while a visitor is
    // reading it — the same window the storefront's prices use.
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });
}

/** Every band of one product, with every language. For the console. */
export function useProductStory(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.showcase.story(productId ?? ''),
    enabled: productId !== null && productId !== '',
    queryFn: async () => {
      const { data, error, response } = await client.GET(
        '/api/v1/staff/products/{productId}/showcase',
        { params: { path: { productId: productId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Writing and publishing both invalidate, and never write to the cache.
 *
 * The server decides what a band's id is, what order they come back in and
 * when a page was published; a screen that guessed any of the three would
 * disagree with it a moment later. This is U3's rule — if the server
 * derives the state, invalidate and let the server answer.
 */
function useStoryWrite<TVariables, TData>(
  productId: string,
  mutationFn: (variables: TVariables) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.showcase.story(productId) }),
        // The public read is keyed by code and this one knows an id, so the
        // whole branch goes: a story is published once in a while, and a
        // stale shop window is worse than a refetch nobody notices.
        queryClient.invalidateQueries({ queryKey: keys.showcase.all }),
      ]);
    },
  });
}

export function useWriteProductStory(productId: string) {
  const client = useApiClient();

  return useStoryWrite(productId, async (blocks: readonly ShowcaseBlockInput[]) => {
    const { data, error, response } = await client.PUT(
      '/api/v1/staff/products/{productId}/showcase',
      { params: { path: { productId } }, body: { blocks: [...blocks] } },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.blocks;
  });
}

/**
 * A picture for the product's page.
 *
 * **The bytes are the body**, as `uploadAsset` does it: no form to parse,
 * no boundary to get wrong, and `X-Filename` for the label. The generated
 * client is still the only door — the body is a `File`, which `fetch`
 * sends as-is.
 *
 * It does **not** invalidate the story: uploading a picture does not put
 * it on a band. Choosing which band carries it is the next act, and it is
 * saved with the rest of the story.
 */
export function useUploadShowcaseAsset(productId: string) {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (file: File) => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/products/{productId}/assets',
        {
          params: { path: { productId }, header: { 'X-Filename': file.name } },
          body: file as unknown as string,
          bodySerializer: (body: unknown) => body as BodyInit,
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.asset;
    },
  });
}

export function usePublishProductStory(productId: string) {
  const client = useApiClient();

  return useStoryWrite(productId, async (published: boolean) => {
    const { data, error, response } = await client.POST(
      '/api/v1/staff/products/{productId}/showcase/publish',
      { params: { path: { productId } }, body: { published } },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.published_at;
  });
}
