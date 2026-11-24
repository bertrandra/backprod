import { useEffect, useLayoutEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

import { contentFrom, sectionsFrom } from '@/features/showcase/blocks/fromApi';
import { BAND_META, type BandKind } from '@/features/showcase/blocks/meta';
import { Showcase } from '@/features/showcase/Showcase';
import { useStorefront } from '@/queries/storefront';
import { buttonClass } from '@/ui/Field';
import { t, type LocaleCode } from '@/i18n';

import { draftAsPage } from './storyDraft';
import type { StoryState } from './storyReview';

/**
 * The page being written, and written *in* (2026-11-21).
 *
 * **It is the real page, not a picture of it.** The draft becomes the shape
 * the public read sends (`draftAsPage`) and goes through `contentFrom()` and
 * `<Showcase>` — the parser and the component a stranger's browser runs. A
 * canvas with its own renderer would be a second opinion about what a band
 * looks like, and it would be the one nobody maintained.
 *
 * **In a frame of its own, so a phone is a phone.** Every band's layout is
 * decided by the width of the *viewport* (`md:`, `lg:`), so a narrow column
 * in a wide window would still draw the desktop page, squeezed. An iframe
 * has a viewport: at 390 px wide the page inside it is the phone's page,
 * breakpoints and all. The React tree is portalled into it, so it is still
 * this application — same client, same cache, same translations.
 *
 * **A click chooses a band; a double click rewrites a sentence.** It was a
 * preview beside a form until today: somebody read a word on the page,
 * looked for the box that held it, and typed there. The sentence is now
 * typed where it is read. The form did not go away — it is the panel
 * beside the page, for what a page cannot show: the other languages, a
 * picture's description, an icon.
 *
 * **A sentence is found by what it says**, not by a mark each band would
 * have to carry: the text double-clicked is looked up among that band's own
 * fields, in the language being read. Fourteen band components know nothing
 * of the editor, and a fifteenth is editable by existing. What that cannot
 * find — a price, a default title drawn from the catalogue of words — is
 * simply not editable here, and the panel is one click away.
 *
 * **Nothing in it is saved, and nothing in it can buy.** The prices are the
 * product's real public offers, read the way a stranger reads them; choosing
 * one does nothing here, and a link that would leave the page is held.
 */
export function StoryCanvas({
  product,
  state,
  device,
  locale,
  selected,
  onSelect,
  canRewrite,
  onRewrite,
}: {
  product: { code: string; name: string };
  state: StoryState;
  device: Device;
  locale: LocaleCode;
  selected: BandKind | null;
  onSelect: (band: BandKind) => void;
  /** Whether this text, in this band, is a sentence the draft holds. */
  canRewrite: (band: BandKind, text: string, isTitle: boolean) => boolean;
  onRewrite: (band: BandKind, was: string, now: string, isTitle: boolean) => void;
}) {
  const storefront = useStorefront(product.code);
  const page = draftAsPage(product, [...state.draft], state.order, state.headings, locale);

  const bandOf = (element: Element): { kind: BandKind; anchor: string } | null => {
    const anchor = element.closest('[data-band]')?.getAttribute('data-band') ?? null;
    const kind = (Object.keys(BAND_META) as BandKind[]).find((candidate) => BAND_META[candidate].anchor === anchor);

    return kind === undefined || anchor === null ? null : { kind, anchor };
  };

  const choose = (event: MouseEvent<HTMLDivElement>) => {
    const target = event.target as Element;

    // **No link navigates the frame** (2026-11-23). One out of the page
    // would replace the draft with somebody else's site — and one to a place
    // on it does too: the frame's document is `about:srcdoc`, whose base is
    // the console's own address, so `#prices` resolved to the console page
    // and loaded it into the frame, where the live site's `X-Frame-Options:
    // DENY` drew "www.raillard.org refused to connect". Locally nothing sends
    // that header, which is why it was only seen in production.
    //
    // A place on the page is scrolled to instead, which is what it means.
    const link = target.closest('a');

    if (link !== null) {
      event.preventDefault();

      const href = link.getAttribute('href') ?? '';

      if (href.startsWith('#') && href.length > 1) {
        const place = link.ownerDocument.getElementById(decodeURIComponent(href.slice(1)));

        if (place !== null && typeof place.scrollIntoView === 'function') {
          place.scrollIntoView({ block: 'start' });
        }
      }
    }

    const band = bandOf(target);

    if (band !== null && target.closest('[data-rewriting]') === null) {
      onSelect(band.kind);
    }
  };

  const rewrite = (event: MouseEvent<HTMLDivElement>) => {
    const element = event.target as HTMLElement;
    const band = bandOf(element);
    const was = (element.textContent ?? '').trim();

    // A leaf, or the words are several elements' and no one field's.
    if (band === null || was === '' || element.children.length > 0 || element.hasAttribute('data-rewriting')) {
      return;
    }

    const isTitle = element.id === `${band.anchor}-title`;

    if (!canRewrite(band.kind, was, isTitle)) {
      return;
    }

    // React's own text node, kept: it is put back before React is told the
    // new words, so React updates the node it knows and not a stranger the
    // browser made while somebody typed.
    const original = element.firstChild;
    let kept = true;

    element.setAttribute('contenteditable', 'plaintext-only');

    if (element.contentEditable !== 'plaintext-only') {
      element.setAttribute('contenteditable', 'true');
    }

    element.setAttribute('data-rewriting', '');
    element.focus();
    element.ownerDocument.getSelection()?.selectAllChildren(element);

    const onKeyDown = (pressed: KeyboardEvent) => {
      if (pressed.key === 'Enter' || pressed.key === 'Escape') {
        pressed.preventDefault();
        kept = pressed.key === 'Enter';
        element.blur();
      }
    };

    const onBlur = () => {
      element.removeEventListener('keydown', onKeyDown);
      element.removeEventListener('blur', onBlur);

      const now = (element.textContent ?? '').replace(/\s+/g, ' ').trim();

      element.removeAttribute('contenteditable');
      element.removeAttribute('data-rewriting');

      if (original !== null) {
        original.nodeValue = was;
        element.replaceChildren(original);
      }

      // Emptied is not rewritten: a required sentence cannot be removed by
      // a slip of the key, and an optional one is removed in the panel.
      if (kept && now !== '' && now !== was) {
        onRewrite(band.kind, was, now, isTitle);
      }
    };

    element.addEventListener('keydown', onKeyDown);
    element.addEventListener('blur', onBlur);
  };

  return (
    <PreviewFrame width={DEVICE_WIDTH[device]} label={t("The product's page, as it is being written")}>
      <Chosen anchor={selected === null ? null : BAND_META[selected].anchor} />

      {/* Capture, so the band is chosen even where a control inside it
          stops the click — a carousel's arrow, an answer that opens. */}
      <div data-testid="canvas-page" onClickCapture={choose} onDoubleClick={rewrite}>
        <Showcase
          productName={product.name}
          content={contentFrom(page)}
          sections={sectionsFrom(page)}
          offers={storefront.data?.offers ?? []}
          offersLoading={storefront.isPending}
          buyLabel={t("See this offer")}
          action={
            // The call to action a stranger gets (spec §3), and a link that
            // stays inside the frame: it scrolls the page to the prices.
            <a href={`#${BAND_META.PRICING.anchor}`} className={buttonClass()}>
              {t("See the offers")}
            </a>
          }
        />
      </div>
    </PreviewFrame>
  );
}

/**
 * The chosen band, outlined, and brought into view.
 *
 * A stylesheet and not a class on the band: the bands are the page's own
 * components and take nothing from the editor. Drawn inside the frame, so
 * `ownerDocument` is the page's document and not the console's.
 */
function Chosen({ anchor }: { anchor: string | null }) {
  const here = useRef<HTMLStyleElement>(null);

  useEffect(() => {
    if (anchor === null) {
      return;
    }

    const band = here.current?.ownerDocument.querySelector(`[data-band="${anchor}"]`);

    // Absent in a test environment, which has no layout to scroll.
    if (band !== null && band !== undefined && typeof band.scrollIntoView === 'function') {
      band.scrollIntoView({ block: 'nearest' });
    }
  }, [anchor]);

  return (
    <style ref={here} data-testid="canvas-chosen" data-anchor={anchor ?? ''}>
      {[
        '[data-band]{cursor:default}',
        '[data-band]:hover{outline:1px dashed var(--color-accent);outline-offset:-1px}',
        '[data-rewriting]{outline:2px solid var(--color-accent);outline-offset:4px;border-radius:2px;cursor:text}',
        anchor === null ? '' : `[data-band="${anchor}"]{outline:3px solid var(--color-accent) !important;outline-offset:-3px}`,
      ].join('')}
    </style>
  );
}

export type Device = 'desktop' | 'tablet' | 'mobile';

export const DEVICES: readonly Device[] = ['desktop', 'tablet', 'mobile'];

export const DEVICE_LABELS: Record<Device, string> = { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Phone' };

/**
 * The viewport each device is drawn at, in CSS pixels.
 *
 * 1280 is past every breakpoint the page uses (`lg` is 1024), 820 sits
 * between `md` and `lg` — the one width where a band is neither the phone's
 * nor the desktop's — and 390 is the commonest phone. They are the frame's
 * *own* width: the frame is then scaled down to whatever room the panel
 * has, so a desktop page is shown whole and small rather than cropped.
 */
const DEVICE_WIDTH: Record<Device, number> = { desktop: 1280, tablet: 820, mobile: 390 };

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
