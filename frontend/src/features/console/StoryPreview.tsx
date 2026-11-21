import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

import { contentFrom, sectionsFrom } from '@/features/showcase/blocks/fromApi';
import { BAND_META, type BandKind } from '@/features/showcase/blocks/meta';
import { Showcase } from '@/features/showcase/Showcase';
import { useStorefront } from '@/queries/storefront';
import { buttonClass } from '@/ui/Field';
import { LOCALE_NAMES, LOCALES, t, type LocaleCode } from '@/i18n';

import { draftAsPage, type DraftBlock, type HeadingDraft } from './storyDraft';

/**
 * The page being written, drawn beside the form that writes it (2026-11-20).
 *
 * **The console had a form and no page.** An operator wrote a headline into
 * a box, pressed Save, pressed Publish, opened the product's address in
 * another tab and found out what it looked like — and a draft, which is
 * what a page is for most of an afternoon, could not be looked at at all,
 * because the public read answers 404 until it is published.
 *
 * **It is the real page, not a picture of it.** The draft becomes the shape
 * the public read sends (`draftAsPage`) and goes through `contentFrom()` and
 * `<Showcase>` — the parser and the component a stranger's browser runs. A
 * preview with its own renderer would be a second opinion about what a band
 * looks like, and it would be the one nobody maintained.
 *
 * **In a frame of its own, so a phone is a phone.** Every band's layout is
 * decided by the width of the *viewport* (`md:`, `lg:`), so a narrow column
 * in a wide window would still draw the desktop page, squeezed. An iframe
 * has a viewport: at 390 px wide the page inside it is the phone's page,
 * breakpoints and all. The React tree is portalled into it, so it is still
 * this application — same client, same cache, same translations — and the
 * frame carries copies of this document's stylesheets.
 *
 * **Nothing in it is saved, and nothing in it can buy.** The prices are the
 * product's real public offers, read the way a stranger reads them, so the
 * prices band is the one a visitor will see; choosing an offer does nothing
 * here.
 */
export function StoryPreview({
  product,
  draft,
  order,
  headings,
}: {
  product: { code: string; name: string };
  draft: readonly DraftBlock[];
  order: readonly BandKind[];
  headings: HeadingDraft;
}) {
  const [device, setDevice] = useState<Device>('desktop');
  const [locale, setLocale] = useState<LocaleCode>('en');
  const storefront = useStorefront(product.code);

  const page = draftAsPage(product, draft, order, headings, locale);

  return (
    <div className="flex h-full min-h-0 flex-col" data-testid="story-preview">
      <div className="flex flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2">
        <div role="group" aria-label={t("Screen size")} className="flex gap-1">
          {DEVICES.map((candidate) => (
            <button
              key={candidate}
              type="button"
              aria-pressed={device === candidate}
              data-testid={`preview-${candidate}`}
              className={toggleClass(device === candidate)}
              onClick={() => setDevice(candidate)}
            >
              {t(DEVICE_LABELS[candidate])}
            </button>
          ))}
        </div>

        <label className="ml-auto flex items-center gap-2 text-xs text-muted">
          {t("Read in")}
          <select
            data-testid="preview-language"
            className="rounded-control border border-line bg-surface px-2 py-1 text-xs text-ink"
            value={locale}
            onChange={(event) => setLocale(event.target.value as LocaleCode)}
          >
            {LOCALES.map((code) => (
              <option key={code} value={code}>
                {LOCALE_NAMES[code]}
              </option>
            ))}
          </select>
        </label>
      </div>

      <PreviewFrame width={DEVICE_WIDTH[device]} label={t("Preview of the product's page")}>
        <Showcase
          productName={product.name}
          content={contentFrom(page)}
          sections={sectionsFrom(page)}
          offers={storefront.data?.offers ?? []}
          offersLoading={storefront.isPending}
          buyLabel={t("See this offer")}
          action={
            // The call to action a stranger gets (spec §3), and a link that
            // stays inside the frame: it scrolls the preview to the prices.
            <a href={`#${BAND_META.PRICING.anchor}`} className={buttonClass()}>
              {t("See the offers")}
            </a>
          }
        />
      </PreviewFrame>
    </div>
  );
}

type Device = 'desktop' | 'mobile';

const DEVICES: readonly Device[] = ['desktop', 'mobile'];

const DEVICE_LABELS: Record<Device, string> = { desktop: 'Desktop', mobile: 'Phone' };

/**
 * The viewport each device previews at, in CSS pixels.
 *
 * 1280 is past every breakpoint the page uses (`lg` is 1024), and 390 is
 * the commonest phone. They are the frame's *own* width: the frame is then
 * scaled down to whatever room the panel has, so a desktop page is shown
 * whole and small rather than cropped.
 */
const DEVICE_WIDTH: Record<Device, number> = { desktop: 1280, mobile: 390 };

function toggleClass(on: boolean): string {
  return [
    'rounded-control px-3 py-1.5 text-xs transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
    on ? 'bg-well font-medium text-ink' : 'text-muted hover:text-ink',
  ].join(' ');
}

/**
 * A viewport of a chosen width, scaled to fit, with this React tree inside.
 *
 * **`srcDoc`, and the load event.** A frame with no address starts on a
 * blank document that some browsers replace once it has "loaded", taking
 * anything portalled into the first one with it. An explicit empty document
 * has one load and one body, and the portal waits for it.
 *
 * **The stylesheets are copied, once per document.** The page inside is
 * styled by the same rules as the page outside — the bundle's stylesheet and
 * whatever theme is in force — so the nodes are imported rather than
 * re-requested by address, which also carries a `<style>` the development
 * server injects and that has no address at all.
 *
 * The scale is presentation and nothing else: the frame keeps its own width
 * in CSS pixels, which is what its media queries read, and is shrunk by a
 * transform, which they do not.
 */
function PreviewFrame({ width, label, children }: { width: number; label: string; children: ReactNode }) {
  const box = useRef<HTMLDivElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [body, setBody] = useState<HTMLElement | null>(null);
  const [room, setRoom] = useState<{ width: number; height: number } | null>(null);

  const adopt = () => {
    const inside = frame.current?.contentDocument ?? null;

    if (inside === null || inside.body === null) {
      return;
    }

    if (inside.head.querySelector('[data-preview-styles]') === null) {
      const marker = inside.createElement('meta');
      marker.setAttribute('data-preview-styles', '');
      inside.head.appendChild(marker);

      for (const sheet of document.querySelectorAll('link[rel="stylesheet"], style')) {
        inside.head.appendChild(inside.importNode(sheet, true));
      }
    }

    // What the theme and the language hang on: the root element's own
    // attributes, which a copied stylesheet reads and a new document lacks.
    for (const attribute of document.documentElement.attributes) {
      inside.documentElement.setAttribute(attribute.name, attribute.value);
    }

    inside.body.className = document.body.className;
    setBody(inside.body);
  };

  // Once on mount as well as on load: a test environment's frame is ready
  // at once and never fires the event.
  useEffect(adopt, []);

  useLayoutEffect(() => {
    const element = box.current;

    if (element === null) {
      return;
    }

    const measure = () => setRoom({ width: element.clientWidth, height: element.clientHeight });

    measure();

    if (typeof ResizeObserver === 'undefined') {
      return;
    }

    const observer = new ResizeObserver(measure);
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  // Never enlarged: a phone shown at twice its size is not what a phone
  // shows. Unmeasured (no layout yet, or a test) is drawn at full size.
  const scale = room === null || room.width === 0 ? 1 : Math.min(1, room.width / width);
  const height = room === null || room.height === 0 ? 800 : room.height / scale;

  return (
    <div ref={box} className="relative min-h-0 flex-1 overflow-hidden bg-well" data-testid="preview-frame-box">
      <iframe
        ref={frame}
        title={label}
        srcDoc="<!doctype html><html><head></head><body></body></html>"
        onLoad={adopt}
        data-testid="preview-frame"
        data-width={width}
        className="absolute left-1/2 top-0 origin-top border-0 bg-canvas shadow-float"
        style={{
          width: `${String(width)}px`,
          height: `${String(height)}px`,
          transform: `translateX(-50%) scale(${String(scale)})`,
        }}
      />

      {body !== null && createPortal(children, body)}
    </div>
  );
}
