import { t } from '@/i18n';

import type { ShowcaseImage } from './blocks/content';

/**
 * One picture of the real product, given room to be looked at.
 *
 * **No device mockup.** A browser chrome or a phone bezel drawn around a
 * screenshot dates the page to the year that chrome was drawn, and adds
 * nothing a reader did not already know about where software runs.
 *
 * **The box is reserved before the bytes arrive.** `aspect-ratio` from the
 * row, so a picture loading late pushes nothing down — a page that reflows
 * under a reader's eyes is the cheapest way to look unfinished, and on a
 * phone it is how somebody taps the wrong thing.
 *
 * **`shadow-float` on something that is not floating**, which is the one
 * place on this platform that happens and is deliberate: the picture is
 * what the band exists for, and the two shadow levels are what the design
 * system has. Under `md` there is no frame at all — the picture runs to the
 * gutter, where a phone gives it the most room it will ever have.
 *
 * With no picture it renders **nothing**, not a grey rectangle: a product
 * that has uploaded none has a text hero, which is a page somebody wrote
 * rather than a page waiting for an asset.
 */
export function ShowcaseImageFrame({
  image,
  priority = false,
}: {
  image: ShowcaseImage | null;
  priority?: boolean;
}) {
  if (image === null) {
    return null;
  }

  return (
    <figure
      data-testid="showcase-image"
      className="overflow-hidden md:rounded-card md:border md:border-line md:shadow-float"
      style={{ aspectRatio: image.ratio > 0 ? image.ratio : 16 / 10 }}
    >
      <img
        src={image.url}
        alt={image.alt === '' ? t("A screenshot of the product") : image.alt}
        loading={priority ? 'eager' : 'lazy'}
        // The hero's picture is the largest thing in the first frame, so it
        // is fetched with the document rather than after it.
        fetchPriority={priority ? 'high' : 'auto'}
        decoding="async"
        className="size-full object-cover"
      />
    </figure>
  );
}
