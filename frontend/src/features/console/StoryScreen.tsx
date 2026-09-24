import { useState } from 'react';

import {
  authoredBandsInOrder,
  BandFieldEditor,
  BAND_FIELDS,
} from '@/features/showcase/blocks/editors';
import { BAND_META, type AuthoredBandKind } from '@/features/showcase/blocks/meta';
import {
  useProductStory,
  usePublishProductStory,
  useWriteProductStory,
  type ShowcaseBlock,
  type ShowcaseBlockInput,
} from '@/queries/showcase';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { Section } from '@/ui/Page';
import type { Translated } from '@/ui/TranslatedField';
import { t } from '@/i18n';

/**
 * `console.admin.showcase` — the story a product tells on its own page.
 *
 * **Driven by the same registry the page renders from.** The bands, their
 * order and their fields come from `features/showcase/blocks`, so a band
 * added tomorrow gets its form by existing rather than by somebody
 * remembering to write one here. That is the promise of
 * `docs/home-showcase-spec.md` §6, and this screen is where it is either
 * kept or quietly broken.
 *
 * **Writing and publishing are two acts**, which is why there are two
 * buttons and they do not run together: four bands can be written over an
 * afternoon without a stranger reading the half-finished ones.
 *
 * **Nothing is optimistic.** A block's id, its order and the moment a page
 * was published are the server's answers; a screen that guessed them would
 * disagree a moment later, and the id it invented would be the one the next
 * save deleted.
 */
export function StoryScreen({ productId }: { productId: string }) {
  const story = useProductStory(productId);
  const write = useWriteProductStory(productId);
  const publish = usePublishProductStory(productId);

  const [draft, setDraft] = useState<DraftBlock[] | null>(null);

  // The server's answer is the starting point, and re-becomes it after a
  // save. Derived **during render** from the answer that arrived, the way
  // React resets state on a prop change — not in an effect, which would
  // paint the empty form first and then replace it, and which the lint
  // rule about cascading renders is exactly about.
  //
  // Not `useState(initialiser)` either: the story arrives after the first
  // render, and a draft seeded from `undefined` would be an empty page
  // somebody could then save over a real one.
  const loaded = story.data?.blocks;
  const [seen, setSeen] = useState(loaded);

  if (loaded !== undefined && loaded !== seen) {
    setSeen(loaded);
    setDraft(loaded.map(toDraft));
  }

  if (story.isPending || draft === null) {
    return <SkeletonRows rows={6} />;
  }

  if (story.error !== null) {
    return <ErrorSurface error={story.error} onRetry={() => void story.refetch()} />;
  }

  const published = story.data.published_at !== null;

  return (
    <div className="space-y-8">
      <Section
        title={t("The product's page")}
        description={t("What this product says about itself at its own address. A band nobody has written does not appear, and a page with nothing in it is the product's name and its prices — which is a page, not a hole.")}
        actions={
          <>
            <Button
              type="button"
              variant="secondary"
              pending={publish.isPending}
              data-testid="publish-story"
              onClick={() => publish.mutate(!published)}
            >
              {published ? t("Take it down") : t("Publish")}</Button>
            <Button
              type="button"
              pending={write.isPending}
              data-testid="save-story"
              onClick={() => write.mutate(draft.map(toInput))}
            >
              {t("Save")}</Button>
          </>
        }
        meta={
          <span data-testid="story-state" data-published={published ? 'true' : 'false'}>
            {published ? t("Published") : t("Draft — nobody outside can read it")}
          </span>
        }
      >
        {write.error !== null && <ErrorSurface error={write.error} />}
        {publish.error !== null && <ErrorSurface error={publish.error} />}

        {draft.length === 0 ? (
          <EmptyState
            title={t("Nothing written yet")}
            description={t("Add a band below. A headline is the one a page cannot be published without.")}
          />
        ) : (
          <ul className="space-y-4" data-testid="story-bands">
            {draft.map((block, index) => (
              <li
                key={block.key}
                data-band-kind={block.block}
                className="space-y-3 rounded-card border border-line bg-surface p-5 shadow-raise"
              >
                <div className="flex flex-wrap items-baseline gap-3">
                  <h3 className="font-medium">{t(BAND_META[block.block].nav)}</h3>
                  <Button
                    type="button"
                    variant="secondary"
                    className="ml-auto"
                    data-testid={`remove-${block.key}`}
                    onClick={() => setDraft((current) => (current ?? []).filter((_, at) => at !== index))}
                  >
                    {t("Remove")}</Button>
                </div>

                {BAND_FIELDS[block.block].map((field) => (
                  <BandFieldEditor
                    key={field.name}
                    id={`${block.key}-${field.name}`}
                    field={field}
                    value={block.fields[field.name] ?? EMPTY}
                    onChange={(next) =>
                      setDraft((current) =>
                        (current ?? []).map((candidate, at) =>
                          at === index
                            ? { ...candidate, fields: { ...candidate.fields, [field.name]: next } }
                            : candidate,
                        ),
                      )
                    }
                  />
                ))}
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section
        className="border-t border-line pt-6"
        title={t("Add a band")}
        description={t("In the order the page reads them. A band may be added more than once — three steps, two use cases — except the headline, which a page has one of.")}
      >
        <div className="flex flex-wrap gap-2">
          {authoredBandsInOrder().map((kind) => (
            <Button
              key={kind}
              type="button"
              variant="secondary"
              data-testid={`add-${kind}`}
              disabled={kind === 'HEADLINE' && draft.some((block) => block.block === 'HEADLINE')}
              // The updater form, never `[...draft, …]`: two clicks in one
              // tick both close over *this render's* `draft`, so the second
              // overwrites the first and adding two bands adds one. Found
              // by clicking the real screen, not by reading it.
              onClick={() => setDraft((current) => [...(current ?? []), blank(kind)])}
            >
              {t(BAND_META[kind].nav)}
            </Button>
          ))}
        </div>
      </Section>
    </div>
  );
}

const EMPTY: Translated = { en: '' };

/**
 * A band being edited: its fields in five languages, and a key.
 *
 * The key is local and never sent. A block's id is the server's to mint —
 * the story is replaced wholly on every save, so an id the console held on
 * to would name a row the save had just deleted.
 */
interface DraftBlock {
  readonly key: string;
  readonly block: AuthoredBandKind;
  readonly fields: Record<string, Translated>;
}

let minted = 0;

function blank(block: AuthoredBandKind): DraftBlock {
  minted += 1;

  return {
    key: `new-${String(minted)}`,
    block,
    fields: Object.fromEntries(BAND_FIELDS[block].map((field) => [field.name, EMPTY])),
  };
}

function toDraft(block: ShowcaseBlock): DraftBlock {
  const kind = block.block;
  const content = block.content as Record<string, string | undefined>;
  const translations = block.translations as Record<string, Record<string, string | undefined> | undefined>;

  return {
    key: block.id,
    block: kind,
    fields: Object.fromEntries(
      BAND_FIELDS[kind].map((field) => [
        field.name,
        {
          en: content[field.name] ?? '',
          fr: translations.fr?.[field.name] ?? '',
          es: translations.es?.[field.name] ?? '',
          de: translations.de?.[field.name] ?? '',
          it: translations.it?.[field.name] ?? '',
        },
      ]),
    ),
  };
}

/**
 * A band, as the contract takes it.
 *
 * `position` is the band's place among its own kind, minted from the order
 * on screen: dragging is not built, and a number nobody can see is better
 * derived than typed. In tens, `display_order`'s habit.
 *
 * A language that says nothing in a field is left out, which is how a
 * translation is removed — the server replaces the set.
 */
function toInput(block: DraftBlock, index: number): ShowcaseBlockInput {
  const content: Record<string, string> = {};
  const translations: Record<string, Record<string, string>> = {};

  for (const field of BAND_FIELDS[block.block]) {
    const value = block.fields[field.name] ?? EMPTY;
    const english = value.en.trim();

    if (english !== '') {
      content[field.name] = english;
    }

    for (const locale of ['fr', 'es', 'de', 'it'] as const) {
      const written = (value[locale] ?? '').trim();

      if (written !== '') {
        translations[locale] = { ...translations[locale], [field.name]: written };
      }
    }
  }

  return {
    block: block.block,
    position: (index + 1) * 10,
    content,
    ...(Object.keys(translations).length === 0 ? {} : { translations }),
  };
}
