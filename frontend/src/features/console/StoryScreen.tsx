import { useState } from 'react';

import {
  authoredBandsInOrder,
  BandFieldEditor,
  BAND_FIELDS,
} from '@/features/showcase/blocks/editors';
import {
  AT_MOST_BANDS,
  BAND_META,
  bandsInOrder,
  carriesAPicture,
  SECTIONS_WITH_A_HEADING,
  SINGLE_BANDS,
  type BandKind,
} from '@/features/showcase/blocks/meta';
import type { StoryTemplate } from '@/features/showcase/blocks/templates';
import {
  useProductStory,
  usePublishProductStory,
  useWriteProductStory,
} from '@/queries/showcase';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { Section } from '@/ui/Page';

import { BandHeadingEditor } from './BandHeading';
import { BandPicture } from './BandPicture';
import { SectionOrder } from './SectionOrder';
import { StoryEditor } from './StoryEditor';
import {
  blank,
  EMPTY,
  headingsToDraft,
  headingsToInput,
  toDraft,
  toInput,
  withTemplate,
  type DraftBlock,
  type HeadingDraft,
} from './storyDraft';
import { TemplateChoice } from './TemplateChoice';
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
export function StoryScreen({
  productId,
  product,
}: {
  productId: string;
  /**
   * The product's code and name, for the preview: its prices are read by
   * code, the way a stranger reads them. Absent, the screen is the form
   * alone — which is what it was before 2026-11-20.
   */
  product?: { code: string; name: string } | undefined;
}) {
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
  // The order, drafted beside the bands and saved with them. Through
  // `bandsInOrder` so the console completes a short or stale order exactly
  // as the page does — one rule, not two that agree today.
  const [order, setOrder] = useState<readonly BandKind[] | null>(null);
  // Every section's heading, in five languages, drafted beside the rows and
  // saved with them.
  const [headings, setHeadings] = useState<HeadingDraft | null>(null);
  // The form with the page beside it, filling the window. A state of this
  // screen and not a route: what is being edited is a draft held here, and
  // an address could not carry it.
  const [studio, setStudio] = useState(false);

  if (loaded !== undefined && loaded !== seen) {
    setSeen(loaded);
    setDraft(loaded.map(toDraft));
    setOrder(bandsInOrder(story.data?.sections));
    setHeadings(headingsToDraft(story.data?.bands));
  }

  if (story.isPending || draft === null || order === null || headings === null) {
    return <SkeletonRows rows={6} />;
  }

  if (story.error !== null) {
    return <ErrorSurface error={story.error} onRetry={() => void story.refetch()} />;
  }

  const published = story.data.published_at !== null;

  const applyTemplate = (template: StoryTemplate) => {
    const next = withTemplate({ draft, order, headings }, template);

    setDraft([...next.draft]);
    setOrder(next.order);
    setHeadings(next.headings);
  };

  // One write for the whole page, order included: two writes for one
  // afternoon's work is two chances to leave it half changed.
  const save = () =>
    write.mutate({
      blocks: draft.map(toInput),
      sections: [...order],
      bands: headingsToInput(headings),
    });

  const editor = (
    <div className="space-y-8">
      <Section
        title={t("The product's page")}
        description={t("What this product says about itself at its own address. A band nobody has written does not appear, and a page with nothing in it is the product's name and its prices — which is a page, not a hole.")}
        actions={
          <>
            {product !== undefined && !studio && (
              <Button
                type="button"
                variant="secondary"
                data-testid="open-studio"
                onClick={() => setStudio(true)}
              >
                {t("Open the page editor")}</Button>
            )}
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
              onClick={save}
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

                {carriesAPicture(block.block) && (
                  <BandPicture
                    productId={productId}
                    image={block.image}
                    assetId={block.assetId}
                    onChoose={(assetId, shownAt) =>
                      setDraft((current) =>
                        (current ?? []).map((candidate, at) =>
                          // The picture changes with the choice. It used to
                          // keep the address of the one it replaced, so the
                          // form showed the old picture under a new id.
                          at === index ? { ...candidate, assetId, image: shownAt } : candidate,
                        ),
                      )
                    }
                  />
                )}
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section
        className="border-t border-line pt-6"
        title={t("The order it is read in")}
        description={t("Drag a section, or use the arrows. Prices is here too: it has no form of its own because it reads the catalogue, but where it falls on the page is yours to decide.")}
      >
        <SectionOrder sections={order} onChange={setOrder} />
      </Section>

      <Section
        className="border-t border-line pt-6"
        title={t("What each band is called")}
        description={t("The line above, the title, and the sentence under it. Leave a title empty and the band reads the words it was written with. Prices is here too: it has a heading and no rows, because the amounts come from the catalogue.")}
      >
        <div className={studio ? 'grid gap-4' : 'grid gap-4 md:grid-cols-2'} data-testid="band-headings">
          {SECTIONS_WITH_A_HEADING.map((block) => (
            <BandHeadingEditor
              key={block}
              block={block}
              fields={headings[block] ?? {}}
              onChange={(field, next) =>
                setHeadings((current) => ({
                  ...(current ?? {}),
                  [block]: { ...(current?.[block] ?? {}), [field]: next },
                }))
              }
            />
          ))}
        </div>
      </Section>

      <Section
        className="border-t border-line pt-6"
        title={t("Add a band")}
        description={t("In the order the page reads them. A band may be added more than once — three steps, two use cases — except the headline and the closing call to action, which a page has one of each.")}
      >
        <div className="flex flex-wrap gap-2">
          {authoredBandsInOrder().map((kind) => (
            <Button
              key={kind}
              type="button"
              variant="secondary"
              data-testid={`add-${kind}`}
              disabled={
                (SINGLE_BANDS.includes(kind) && draft.some((block) => block.block === kind)) ||
                draft.filter((block) => block.block === kind).length >= (AT_MOST_BANDS[kind] ?? Infinity)
              }
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

      <Section
        className="border-t border-line pt-6"
        title={t("Start from a template")}
        description={t("An order and sentences that say what goes where, to replace with your own. Only the English is filled, and nothing is saved until you press Save.")}
      >
        <TemplateChoice replaces={draft.length > 0} onChoose={applyTemplate} />
      </Section>
    </div>
  );

  if (!studio || product === undefined) {
    return editor;
  }

  // The editor instead of the form, not over it: the two hold the same
  // draft, and two sets of fields for one sentence would be two ids for
  // one label.
  return (
    <StoryEditor
      productId={productId}
      product={product}
      state={{ draft, order, headings }}
      saved={{
        draft: (loaded ?? []).map(toDraft),
        order: bandsInOrder(story.data.sections),
        headings: headingsToDraft(story.data.bands),
      }}
      onChange={(next) => {
        setDraft([...next.draft]);
        setOrder(next.order);
        setHeadings(next.headings);
      }}
      published={published}
      saving={write.isPending}
      onSave={save}
      publishing={publish.isPending}
      onPublish={() => publish.mutate(!published)}
      problem={
        (write.error !== null || publish.error !== null) && (
          <div className="border-b border-line bg-surface px-3 py-2">
            {write.error !== null && <ErrorSurface error={write.error} />}
            {publish.error !== null && <ErrorSurface error={publish.error} />}
          </div>
        )
      }
      onClose={() => setStudio(false)}
    />
  );
}
