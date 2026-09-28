import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ApiClient, Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { currentLocale } from '@/i18n';

import { keys } from './keys';
import { ApiError, toApiError } from './session';

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
export type ShowcaseBlockContent = Schemas['ShowcaseBlockContent'];
/**
 * The order a product reads its sections in, from the contract.
 *
 * Taken from the generated types rather than spelled as `string[]`, so a
 * section this platform has never heard of does not compile — the same
 * reason `X-Product` is required in the contract rather than remembered.
 */
export type ShowcaseSectionOrder = Schemas['ShowcaseSectionOrder'];

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

/**
 * The story as the console reads it, in one place.
 *
 * Shared between the query below and the translation desk's write, which
 * re-reads the story at the moment it saves one sentence of it. Two spellings
 * of the same read would be two places the path is written, and the second one
 * is the one that goes stale.
 */
async function readStory(client: ApiClient, productId: string) {
  const { data, error, response } = await client.GET(
    '/api/v1/staff/products/{productId}/showcase',
    { params: { path: { productId } } },
  );

  if (error !== undefined || data === undefined) {
    throw toApiError(response.status, error);
  }

  return data;
}

/** Every band of one product, with every language. For the console. */
export function useProductStory(productId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.showcase.story(productId ?? ''),
    enabled: productId !== null && productId !== '',
    queryFn: () => readStory(client, productId ?? ''),
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
        // Every sentence on this page is on the translation desk, which counts
        // what is missing across the whole platform (2026-09-26). A band
        // rewritten here is a row it has to read again, whichever screen the
        // write was made from.
        queryClient.invalidateQueries({ queryKey: keys.staff.translations }),
      ]);
    },
  });
}

/**
 * What the console sends: the whole page, and optionally the order it is
 * read in.
 *
 * `sections` **absent means "leave the order alone"**, never "put it back to
 * the default" — the contract says so and it is load-bearing. The
 * translation desk writes one sentence through this same operation by
 * re-reading the story and sending the blocks back, and it carries no order;
 * if one had to be sent, translating a headline would reorder the page.
 */
export interface StoryWrite {
  readonly blocks: readonly ShowcaseBlockInput[];
  readonly sections?: ShowcaseSectionOrder;
}

export function useWriteProductStory(productId: string) {
  const client = useApiClient();

  return useStoryWrite(productId, async ({ blocks, sections }: StoryWrite) => {
    const { data, error, response } = await client.PUT(
      '/api/v1/staff/products/{productId}/showcase',
      {
        params: { path: { productId } },
        body: {
          blocks: [...blocks],
          ...(sections === undefined ? {} : { sections: [...sections] }),
        },
      },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.blocks;
  });
}

/** What the translation desk saves of one product's story, in one language. */
export interface StoryTranslation {
  /** The language being written. Never `en`: the English is the key. */
  readonly locale: string;
  /**
   * What was typed, by sentence path — `HEADLINE.10.headline`, which is what
   * `listTranslations` answered as `field`. Blank removes that language's
   * translation of that one field.
   *
   * Several at once rather than one call per box, because the write replaces
   * the whole story: two sequential writes would each be built on a read that
   * the other had already invalidated, and the second would undo the first.
   */
  readonly values: Readonly<Record<string, string>>;
}

/** Where in a story one sentence lives, out of its path. */
function pathOf(path: string): { block: string; position: number; field: string } | null {
  const parts = path.split('.');

  // Three at least, and the field is whatever follows the second dot: a band's
  // field names are a closed set the server validates and none has a dot in
  // it, but joining the rest back rather than taking parts[2] means a field
  // that grew one is a refusal below instead of a silent write to `headline`.
  if (parts.length < 3) {
    return null;
  }

  const position = Number(parts[1]);

  if (!Number.isInteger(position)) {
    return null;
  }

  return { block: parts[0] ?? '', position, field: parts.slice(2).join('.') };
}

/** One band's fields, as strings, with the blanks dropped. */
function fieldsOf(content: ShowcaseBlockContent | undefined): Record<string, string> {
  const said: Record<string, string> = {};

  for (const [field, value] of Object.entries(content ?? {})) {
    if (typeof value === 'string' && value.trim() !== '') {
      said[field] = value;
    }
  }

  return said;
}

/**
 * The story it was given, with the sentences somebody typed changed in one
 * language and **everything else passed through verbatim**.
 *
 * This function is the whole difference between the showcase and the rest of
 * the translation desk. `renameFeature` replaces one record's translation set;
 * `writeProductStory` replaces a product's **entire story** — every band, its
 * English, its picture, its position and all four languages — so anything this
 * does not carry forward is deleted. A merge that rebuilt a band from the
 * desk's own row would silently drop the picture, the position and every field
 * of every other language.
 *
 * So the blocks come from a fresh read of the owning operation and are copied
 * field for field. The only thing decided here is one language's value of the
 * fields that were typed.
 *
 * **The band is found by kind and position, never by id**, because writing a
 * story deletes every row and inserts it again: the ids in a read taken before
 * the last save no longer exist. `(block, position)` is unique per product and
 * survives.
 *
 * A band that is no longer there is a **refusal**, not an insertion. Somebody
 * removed it while this was open, and re-creating it from a translation desk
 * would resurrect a band the operator deleted — with no English in it, since
 * this screen does not edit the English.
 */
export function storyWith(
  blocks: readonly ShowcaseBlock[],
  edit: StoryTranslation,
): ShowcaseBlockInput[] {
  /** What was typed, gathered per band: `HEADLINE.10` → field → value. */
  const byBand = new Map<string, Record<string, string>>();

  for (const [path, value] of Object.entries(edit.values)) {
    const where = pathOf(path);

    if (where === null) {
      throw gone(path);
    }

    const at = `${where.block}.${where.position}`;

    byBand.set(at, { ...(byBand.get(at) ?? {}), [where.field]: value });
  }

  const written: ShowcaseBlockInput[] = [];
  const touched = new Set<string>();

  for (const block of blocks) {
    // Every field the server gave back, copied. Not rebuilt from the desk's
    // row, which holds the sentences and nothing else.
    const carried: ShowcaseBlockInput = {
      block: block.block,
      position: block.position,
      content: block.content,
      asset_id: block.asset_id,
      translations: block.translations,
    };

    const at = `${block.block}.${block.position}`;
    const typed = byBand.get(at);

    if (typed === undefined) {
      written.push(carried);

      continue;
    }

    touched.add(at);
    written.push({
      ...carried,
      translations: translationsWith(block.translations, edit.locale, typed),
    });
  }

  for (const at of byBand.keys()) {
    if (!touched.has(at)) {
      throw gone(at);
    }
  }

  return written;
}

function gone(sentence: string): ApiError {
  return new ApiError(
    409,
    'SHOWCASE_SENTENCE_GONE',
    'That band is not on the product’s page any more.',
    { sentence },
    '',
  );
}

/**
 * One band's four languages, with some fields of one of them rewritten.
 *
 * Every other language and every other field of the language being edited are
 * copied, for the reason above. A field emptied is **removed** rather than sent
 * blank, and a language left with no fields is removed with it: the server
 * drops a blank field and stores no row for an empty object, so sending either
 * would be asking for a state it does not keep, and the next read would
 * disagree with what the screen had sent.
 */
function translationsWith(
  translations: ShowcaseBlock['translations'],
  locale: string,
  typed: Readonly<Record<string, string>>,
  // `NonNullable`, because `exactOptionalPropertyTypes` makes "absent" and
  // "undefined" different things: this always answers an object, even an empty
  // one, which is how a band with every translation removed is written.
): NonNullable<ShowcaseBlockInput['translations']> {
  const written: Record<string, Record<string, string>> = {};

  for (const [said, content] of Object.entries(translations ?? {})) {
    written[said] = fieldsOf(content);
  }

  const now = { ...(written[locale] ?? {}) };

  for (const [field, value] of Object.entries(typed)) {
    if (value.trim() === '') {
      delete now[field];
    } else {
      now[field] = value.trim();
    }
  }

  if (Object.keys(now).length === 0) {
    delete written[locale];
  } else {
    written[locale] = now;
  }

  return written;
}

/**
 * One sentence of a product's story, written through the operation that owns
 * the story (2026-09-26).
 *
 * **A read-modify-write, and it says so.** `writeProductStory` replaces the
 * whole story, so changing one sentence means holding the whole of it — and
 * the desk's own read is not the whole of it: it carries the sentences and not
 * the pictures, the positions or the fields that are not sentences. So the
 * story is re-read here, from the operation that owns it, and re-read **at the
 * moment of the write** rather than when the screen opened: the desk is a
 * screen somebody leaves open while they work through a language, and a story
 * read twenty minutes ago is a story that may have had a band removed since.
 *
 * That narrows the window; it does not close it. Between this read and the PUT
 * a moment later, another operator saving the same story would have their work
 * overwritten, and nothing in the contract would refuse it — the showcase write
 * carries no version and takes no `If-Match`. Two things make that a risk worth
 * running rather than a defect: the surface is `/staff/*`, whose whole audience
 * is the handful of people who run the platform, and the write is refused
 * outright when the band it names has gone, which is the shape the collision
 * actually takes. Closing it properly means a precondition on
 * `writeProductStory` itself, which is a change to the operation that owns it
 * and not something a second caller may invent.
 */
export function useTranslateStory(productId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useStoryWrite(productId, async (edit: StoryTranslation) => {
    // `fetchQuery` with no staleness allowed: this has to be the story as it is
    // now, not whatever the cache is holding for the Story screen.
    const story = await queryClient.fetchQuery({
      queryKey: keys.showcase.story(productId),
      queryFn: () => readStory(client, productId),
      staleTime: 0,
    });

    const { data, error, response } = await client.PUT(
      '/api/v1/staff/products/{productId}/showcase',
      { params: { path: { productId } }, body: { blocks: storyWith(story.blocks, edit) } },
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
