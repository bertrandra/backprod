import { useEffect, useRef, useState, type ReactNode } from 'react';

import { authoredBandsInOrder, BAND_FIELDS, type BandField } from '@/features/showcase/blocks/editors';
import {
  AT_MOST_BANDS,
  AUTHORED_BANDS,
  BAND_META,
  carriesAPicture,
  layoutsOf,
  menuIsHidden,
  SECTIONS_WITH_A_HEADING,
  SINGLE_BANDS,
  type AuthoredBandKind,
  type BandKind,
} from '@/features/showcase/blocks/meta';
import type { StoryTemplate } from '@/features/showcase/blocks/templates';
import { useProductDemonstrations, type ProductDemonstration } from '@/queries/staff';
import { Button, buttonClass, inputClass } from '@/ui/Field';
import type { Translated } from '@/ui/TranslatedField';
import { cn } from '@/utils/cn';
import { LOCALE_NAMES, LOCALES, t, type LocaleCode } from '@/i18n';

import { headingSentencesOf } from './BandHeading';
import { BandPicture } from './BandPicture';
import { PanelField } from './PanelField';
import { DEVICE_LABELS, DEVICES, StoryCanvas, type Device } from './StoryCanvas';
import { blank, EMPTY, startOf, withTemplate, type DraftBlock } from './storyDraft';
import {
  bandsOnThePage,
  changesSince,
  missingEnglish,
  untranslated,
  type StoryChange,
  type StoryState,
} from './storyReview';
import { TemplateChoice } from './TemplateChoice';

/**
 * The page editor (2026-11-21): the product's page in the middle, what it
 * is made of on the left, the chosen band's settings on the right.
 *
 * **The console had a form with a preview beside it.** Every sentence was
 * written in a box and read somewhere else; a band was added from a row of
 * fifteen buttons at the bottom of a long form, ordered in a second list
 * and titled in a third. It worked, and nobody would have called it an
 * editor. This is the same draft, the same single write and the same
 * registry of bands — arranged around the page instead of around the
 * database's three tables.
 *
 * **Still one draft, held by the screen that opened this.** The editor
 * receives the state and hands back the next one; it keeps only what is
 * its own — what is chosen, what can be undone, which dialog is open.
 * Closing it loses nothing, exactly as closing the preview did not.
 *
 * **Undo is here because the page is now edited by gesture.** A form field
 * has the browser's undo; a band removed with one click, or dragged, has
 * none. Every change passes through `change()`, which keeps what was there.
 * Typing is kept once per pause rather than once per letter.
 *
 * **Saving is read before it is done.** A published page has no separate
 * draft on the server: saving *is* putting it in front of visitors. The
 * review says what would change and what is still missing, and waits.
 */
export function StoryEditor({
  productId,
  product,
  state,
  saved,
  onChange,
  published,
  saving,
  onSave,
  publishing,
  onPublish,
  problem,
  onClose,
}: {
  productId: string;
  product: { code: string; name: string };
  state: StoryState;
  /** What the server holds: the page a save would be compared with. */
  saved: StoryState;
  onChange: (next: StoryState) => void;
  published: boolean;
  saving: boolean;
  onSave: () => void;
  publishing: boolean;
  onPublish: () => void;
  /** A refused save or publication, drawn under the bar. */
  problem?: ReactNode;
  onClose: () => void;
}) {
  const [selected, setSelected] = useState<BandKind | null>(null);
  const [device, setDevice] = useState<Device>('desktop');
  const [locale, setLocale] = useState<LocaleCode>('en');
  const [pane, setPane] = useState<Pane>('page');
  const [dialog, setDialog] = useState<'library' | 'review' | null>(null);
  const [past, setPast] = useState<readonly StoryState[]>([]);
  const [future, setFuture] = useState<readonly StoryState[]>([]);
  const typing = useRef({ about: '', at: 0 });
  const close = useRef<HTMLButtonElement>(null);

  /**
   * Every change to the page. `about` names what is being typed into: two
   * changes to the same field within a moment are one step back, not forty.
   */
  const change = (next: StoryState, about = '') => {
    const now = Date.now();
    const sameStroke = about !== '' && about === typing.current.about && now - typing.current.at < 1500;

    typing.current = { about, at: now };

    if (!sameStroke) {
      setPast((steps) => [...steps.slice(-99), state]);
    }

    setFuture([]);
    onChange(next);
  };

  const undo = () => {
    const previous = past.at(-1);

    if (previous === undefined) {
      return;
    }

    typing.current = { about: '', at: 0 };
    setPast(past.slice(0, -1));
    setFuture([state, ...future]);
    onChange(previous);
  };

  const redo = () => {
    const next = future[0];

    if (next === undefined) {
      return;
    }

    typing.current = { about: '', at: 0 };
    setFuture(future.slice(1));
    setPast([...past, state]);
    onChange(next);
  };

  // Read through a ref by the one listener, so the listener is attached
  // once and still acts on today's state rather than the first render's.
  const keys = useRef({ undo, redo, onClose, dialog });

  useEffect(() => {
    keys.current = { undo, redo, onClose, dialog };
  });

  useEffect(() => {
    close.current?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      const now = keys.current;

      if (event.key === 'Escape') {
        if (now.dialog !== null) {
          setDialog(null);
        } else {
          now.onClose();
        }

        return;
      }

      const target = event.target as HTMLElement | null;
      const writing = target !== null && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

      // In a field, the keys are the field's own undo.
      if (writing || !(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') {
        return;
      }

      event.preventDefault();

      if (event.shiftKey) {
        now.redo();
      } else {
        now.undo();
      }
    };

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  const present = bandsOnThePage(state);
  const chosen = selected !== null && present.includes(selected) ? selected : null;
  const changes = changesSince(saved, state);

  const choose = (band: BandKind | null, show?: Pane) => {
    setSelected(band);

    if (show !== undefined) {
      setPane(show);
    }
  };

  /** One band's place among the bands on the page, one step either way. */
  const move = (band: BandKind, towards: BandKind) => {
    const from = state.order.indexOf(band);
    const to = state.order.indexOf(towards);

    if (from === -1 || to === -1 || from === to) {
      return;
    }

    const order = state.order.filter((kind) => kind !== band);

    order.splice(order.indexOf(towards) + (from < to ? 1 : 0), 0, band);
    change({ ...state, order });
  };

  const add = (band: AuthoredBandKind) => {
    const order = [...state.order];

    // After the band being looked at, which is where somebody adding one
    // expects it. With nothing chosen it keeps the place the order gives it.
    if (chosen !== null && chosen !== band) {
      order.splice(order.indexOf(band), 1);
      order.splice(order.indexOf(chosen) + 1, 0, band);
    }

    change({ ...state, order, draft: [...state.draft, ...startOf(band)] });
    setDialog(null);
    choose(band, 'panel');
  };

  const said = (value: Translated | undefined): string => {
    const translated = (value?.[locale] ?? '').trim();

    return (translated !== '' ? translated : (value?.en ?? '').trim()).replace(/\s+/g, ' ');
  };

  const written = (value: Translated | undefined, now: string): Translated =>
    locale === 'en' ? { ...(value ?? EMPTY), en: now } : { ...(value ?? EMPTY), [locale]: now };

  /** Which sentence of the draft a text on the page is, if it is one. */
  const locate = (band: BandKind, text: string, isTitle: boolean): Found | null => {
    const sought = text.replace(/\s+/g, ' ');
    const titled = SECTIONS_WITH_A_HEADING.includes(band);

    if (titled) {
      for (const field of headingSentencesOf(band)) {
        if (said(state.headings[band]?.[field.name]) === sought) {
          return { heading: field.name };
        }
      }
    }

    for (const [index, block] of state.draft.entries()) {
      if (block.block !== band) {
        continue;
      }

      for (const field of BAND_FIELDS[block.block]) {
        if (field.choices === undefined && said(block.fields[field.name]) === sought) {
          return { row: index, field: field.name };
        }
      }
    }

    // A title nobody has written reads its default words. Rewriting it is
    // writing one.
    return isTitle && titled ? { heading: 'title' } : null;
  };

  const rewrite = (band: BandKind, was: string, now: string, isTitle: boolean) => {
    const found = locate(band, was, isTitle);

    if (found === null) {
      return;
    }

    if ('heading' in found) {
      const fields = state.headings[band] ?? {};

      change({
        ...state,
        headings: { ...state.headings, [band]: { ...fields, [found.heading]: written(fields[found.heading], now) } },
      });

      return;
    }

    change({
      ...state,
      draft: state.draft.map((block, index) =>
        index === found.row
          ? { ...block, fields: { ...block.fields, [found.field]: written(block.fields[found.field], now) } }
          : block,
      ),
    });
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t("The page editor")}
      data-testid="story-studio"
      className="fixed inset-0 z-40 flex flex-col bg-canvas"
    >
      <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-surface px-3 py-2">
        <button
          ref={close}
          type="button"
          className={toolClass(false)}
          data-testid="close-studio"
          aria-label={t("Close the editor")}
          onClick={onClose}
        >
          <Glyph d="M15 6l-6 6 6 6" />
        </button>

        <div className="min-w-0 leading-tight">
          <p className="truncate text-sm font-semibold">{t("{product} — its page", { product: product.name })}</p>
          <p className="truncate text-xs text-muted" data-testid="editor-state" data-dirty={changes.length > 0 ? 'true' : 'false'}>
            {published ? t("Published") : t("Draft — nobody outside can read it")}
            {' · '}
            {changes.length > 0 ? t("changes not saved") : t("everything is saved")}
          </p>
        </div>

        <div className="flex gap-0.5">
          <button type="button" className={toolClass(false)} data-testid="editor-undo" aria-label={t("Undo")} disabled={past.length === 0} onClick={undo}>
            <Glyph d="M9 7L4 12l5 5M4 12h10a6 6 0 0 1 0 12" />
          </button>
          <button type="button" className={toolClass(false)} data-testid="editor-redo" aria-label={t("Redo")} disabled={future.length === 0} onClick={redo}>
            <Glyph d="M15 7l5 5-5 5M20 12H10a6 6 0 0 0 0 12" />
          </button>
        </div>

        <div role="group" aria-label={t("Screen size")} className="ml-auto flex gap-0.5 rounded-control bg-well p-0.5">
          {DEVICES.map((candidate) => (
            <button
              key={candidate}
              type="button"
              aria-pressed={device === candidate}
              data-testid={`preview-${candidate}`}
              className={toolClass(device === candidate, 'px-2.5 text-xs')}
              onClick={() => setDevice(candidate)}
            >
              {t(DEVICE_LABELS[candidate])}
            </button>
          ))}
        </div>

        <label className="flex items-center gap-2 text-xs text-muted">
          {t("Language")}
          <select
            data-testid="preview-language"
            className="rounded-control border border-line bg-surface px-2 py-1.5 text-xs text-ink"
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

        <div className="ml-auto flex gap-2">
          <Button type="button" variant="secondary" pending={publishing} data-testid="editor-publish" onClick={onPublish}>
            {published ? t("Take it down") : t("Publish")}
          </Button>
          <Button type="button" pending={saving} data-testid="editor-save" disabled={changes.length === 0} onClick={() => setDialog('review')}>
            {t("Save")}
          </Button>
        </div>
      </header>

      {problem}

      <div role="group" aria-label={t("What to show")} className="flex gap-1 border-b border-line bg-surface px-3 py-1.5 lg:hidden">
        {PANES.map((candidate) => (
          <button
            key={candidate}
            type="button"
            aria-pressed={pane === candidate}
            data-testid={`studio-show-${candidate}`}
            className={toolClass(pane === candidate, 'flex-1 px-2 text-xs')}
            onClick={() => setPane(candidate)}
          >
            {t(PANE_LABELS[candidate])}
          </button>
        ))}
      </div>

      <div className="grid min-h-0 flex-1 lg:grid-cols-[15rem_minmax(0,1fr)_23rem]">
        <nav
          aria-label={t("The bands of the page")}
          className={cn('flex min-h-0 flex-col border-line bg-surface lg:border-r', pane === 'structure' ? '' : 'hidden lg:flex')}
        >
          <Structure
            present={present}
            state={state}
            chosen={chosen}
            onChoose={(band) => choose(band, 'panel')}
            onMove={move}
          />

          <div className="border-t border-line p-3">
            <Button type="button" variant="secondary" className="w-full" data-testid="open-library" onClick={() => setDialog('library')}>
              {t("Add a band")}
            </Button>
          </div>
        </nav>

        <div className={cn('flex min-h-0 flex-col', pane === 'page' ? '' : 'hidden lg:flex')}>
          <p className="border-b border-line bg-well px-3 py-1.5 text-center text-xs text-muted">
            {t("Click a band to choose it. Double-click a sentence to rewrite it where it is read.")}
          </p>
          <StoryCanvas
            product={product}
            state={state}
            device={device}
            locale={locale}
            selected={chosen}
            onSelect={(band) => choose(band)}
            canRewrite={(band, text, isTitle) => locate(band, text, isTitle) !== null}
            onRewrite={rewrite}
          />
        </div>

        <aside
          aria-label={t("Settings")}
          data-testid="editor-panel"
          className={cn('min-h-0 overflow-y-auto border-line bg-surface lg:border-l', pane === 'panel' ? '' : 'hidden lg:block')}
        >
          {chosen === null ? (
            <PagePanel
              state={state}
              present={present}
              published={published}
              onMenu={(hidden) => {
                const headline = state.headings.HEADLINE ?? {};

                // The usual one is stored as no choice at all, like a layout.
                change({
                  ...state,
                  headings: { ...state.headings, HEADLINE: { ...headline, menu: { en: hidden ? 'hidden' : '' } } },
                });
              }}
              onTemplate={(template) => {
                change(withTemplate(state, template));
                setSelected(null);
              }}
            />
          ) : (
            <BandPanel
              key={chosen}
              productId={productId}
              productCode={product.code}
              band={chosen}
              locale={locale}
              state={state}
              present={present}
              change={change}
              onMove={move}
              onDone={() => setSelected(null)}
            />
          )}
        </aside>
      </div>

      {dialog === 'library' && <Library state={state} onAdd={add} onClose={() => setDialog(null)} />}

      {dialog === 'review' && (
        <Review
          state={state}
          changes={changes}
          published={published}
          onClose={() => setDialog(null)}
          onConfirm={() => {
            setDialog(null);
            onSave();
          }}
        />
      )}
    </div>
  );
}

type Found = { readonly heading: string } | { readonly row: number; readonly field: string };

type Pane = 'structure' | 'page' | 'panel';

const PANES: readonly Pane[] = ['structure', 'page', 'panel'];

const PANE_LABELS: Record<Pane, string> = { structure: 'Bands', page: 'The page', panel: 'Settings' };

function toolClass(on: boolean, extra = 'w-9'): string {
  return cn(
    'inline-flex h-9 items-center justify-center rounded-control transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-40',
    on ? 'bg-surface font-semibold text-ink shadow-raise' : 'text-muted hover:text-ink',
    extra,
  );
}

function Glyph({ d }: { d: string }) {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={d} />
    </svg>
  );
}

/**
 * The page as a list: the bands a reader meets, in their order.
 *
 * Only the bands **on the page**. The order the server keeps names all
 * fifteen, written or not, and the old screen listed them all — so moving
 * "Questions" above "Prices" meant stepping over four bands that did not
 * exist. A move here is relative to the next band a reader would see, and
 * the full order is rewritten underneath.
 */
function Structure({
  present,
  state,
  chosen,
  onChoose,
  onMove,
}: {
  present: readonly BandKind[];
  state: StoryState;
  chosen: BandKind | null;
  onChoose: (band: BandKind) => void;
  onMove: (band: BandKind, towards: BandKind) => void;
}) {
  const [carrying, setCarrying] = useState<BandKind | null>(null);

  return (
    <ol className="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-2" data-testid="editor-structure">
      {present.map((band) => {
        const rows = state.draft.filter((block) => block.block === band).length;

        return (
          <li
            key={band}
            draggable
            data-structure={band}
            onDragStart={(event) => {
              setCarrying(band);
              event.dataTransfer.setData('text/plain', band);
              event.dataTransfer.effectAllowed = 'move';
            }}
            onDragEnd={() => setCarrying(null)}
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
              event.preventDefault();

              if (carrying !== null) {
                onMove(carrying, band);
              }

              setCarrying(null);
            }}
            className={carrying === band ? 'opacity-50' : undefined}
          >
            <button
              type="button"
              aria-current={chosen === band ? 'true' : undefined}
              data-testid={`structure-${band}`}
              onClick={() => onChoose(band)}
              className={cn(
                'flex w-full items-center gap-2 rounded-control px-2.5 py-2 text-left text-sm',
                'focus-visible:outline-2 focus-visible:outline-offset-2',
                chosen === band ? 'bg-accent-wash font-semibold text-ink' : 'text-ink hover:bg-well',
              )}
            >
              <span aria-hidden="true" className="cursor-grab text-subtle">⠿</span>
              <span className="min-w-0 flex-1 truncate">{t(BAND_META[band].nav)}</span>
              <span className="text-xs font-normal text-muted">{band === 'PRICING' ? t("catalogue") : String(rows)}</span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}

const LAYOUT_NAMES: Record<string, string> = {
  split: 'Side by side',
  centered: 'Centred',
  stacked: 'Stacked',
  columns: 'In columns',
  timeline: 'As a timeline',
  grid: 'As a grid',
  list: 'As a list',
  single: 'One at a time',
  alternate: 'Alternating sides',
  carousel: 'As a carousel',
  gallery: 'As a gallery',
  plain: 'As they are',
  counter: 'Counting up',
  row: 'In a row',
  marquee: 'Passing by',
  cards: 'Cards',
  table: 'Cards and a table',
  overlay: 'One over the other',
  beside: 'Next to each other',
};

/**
 * The chosen band (2026-11-23): what it says, and how it looks.
 *
 * **Two tabs, and one thing in front at a time.** The panel stacked the
 * layout, every row with every field, the band's title and a section to
 * remove it, and the operator's verdict was the right one — too much, not
 * readable. What a band *says* is what somebody opens it to change; how it
 * is laid out and what its title reads are changed once. So they are
 * apart, and removing the band is an icon beside moving it.
 *
 * **One row open at a time**, the first by default; a band with one row has
 * no list at all. A comparison is the exception: its two sides are one
 * thing, and both are always in front.
 */
function BandPanel({
  productId,
  productCode,
  band,
  state,
  present,
  locale,
  change,
  onMove,
  onDone,
}: {
  productId: string;
  productCode: string;
  band: BandKind;
  state: StoryState;
  present: readonly BandKind[];
  locale: LocaleCode;
  change: (next: StoryState, about?: string) => void;
  onMove: (band: BandKind, towards: BandKind) => void;
  onDone: () => void;
}) {
  const authored = (AUTHORED_BANDS as readonly BandKind[]).includes(band) ? (band as AuthoredBandKind) : null;
  const rows = state.draft.map((block, index) => ({ block, index })).filter(({ block }) => block.block === band);
  const [open, setOpen] = useState<string | null>(rows[0]?.block.key ?? null);
  const layouts = layoutsOf(band);
  const titled = SECTIONS_WITH_A_HEADING.includes(band);
  const [tab, setTab] = useState<'content' | 'look'>(authored === null ? 'look' : 'content');
  const at = present.indexOf(band);
  const before = present[at - 1];
  const after = present[at + 1];
  const heading = state.headings[band] ?? {};
  const layout = heading.layout?.en ?? '';
  const full =
    authored !== null &&
    ((SINGLE_BANDS.includes(authored) && rows.length >= 1) || rows.length >= (AT_MOST_BANDS[authored] ?? Infinity));
  const embeds = authored !== null && BAND_FIELDS[authored].some((field) => field.name === EMBED);
  // Asked only where a band can embed one: the read leaves this host.
  const demonstrations = useProductDemonstrations(embeds ? productCode : null).data?.demonstrations ?? [];
  const sideBySide = band === 'COMPARE';
  const unfinished =
    embeds &&
    (rows.some(({ block }) => (block.fields[EMBED]?.en ?? '').trim() === '' && block.assetId === null && block.image === null) ||
      (sideBySide && rows.length < 2));

  /** What waits under *More options*: what a band can do without. */
  const later = (field: string): boolean =>
    field === 'alt' ||
    field === 'ratio' ||
    (field === EMBED && demonstrations.length > 0) ||
    (sideBySide && field === 'caption') ||
    // Placed by clicking the picture; the numbers stay one click away.
    (band === 'HOTSPOT' && (field === 'x' || field === 'y'));

  const setHeading = (field: string, next: Translated, about = '') =>
    change({ ...state, headings: { ...state.headings, [band]: { ...heading, [field]: next } } }, about);

  const setRow = (index: number, next: DraftBlock, about = '') =>
    change({ ...state, draft: state.draft.map((block, place) => (place === index ? next : block)) }, about);

  const swap = (first: number, second: number) => {
    const draft = [...state.draft];
    const held = draft[first];
    const other = draft[second];

    if (held === undefined || other === undefined) {
      return;
    }

    draft[first] = other;
    draft[second] = held;
    change({ ...state, draft });
  };

  const field = (block: DraftBlock, index: number, name: BandField) => (
    <PanelField
      key={name.name}
      id={`${block.key}-${name.name}`}
      field={name}
      value={block.fields[name.name] ?? EMPTY}
      locale={locale}
      onChange={(next) => setRow(index, { ...block, fields: { ...block.fields, [name.name]: next } }, `${block.key}-${name.name}`)}
    />
  );

  const form = (block: DraftBlock, index: number) => (
    <div className="space-y-4">
      {embeds && (
        <DemonstrationPicker
          id={`${block.key}-demonstration`}
          found={demonstrations}
          address={block.fields[EMBED]?.en ?? ''}
          onChoose={(address) => setRow(index, { ...block, fields: { ...block.fields, [EMBED]: { en: address } } })}
        />
      )}

      {band === 'HOTSPOT' && (
        <HotspotPlacer
          picture={rows[0]?.block.image ?? null}
          points={rows.map(({ block: point }) => ({ key: point.key, x: point.fields.x?.en ?? '', y: point.fields.y?.en ?? '' }))}
          current={block.key}
          onPlace={(x, y) => setRow(index, { ...block, fields: { ...block.fields, x: { en: x }, y: { en: y } } })}
        />
      )}

      {carriesAPicture(block.block) && (band !== 'HOTSPOT' || index === rows[0]?.index) && (
        <BandPicture
          productId={productId}
          image={block.image}
          assetId={block.assetId}
          onChoose={(assetId, shownAt) => setRow(index, { ...block, assetId, image: shownAt })}
        />
      )}

      {BAND_FIELDS[block.block].filter((candidate) => !later(candidate.name)).map((candidate) => field(block, index, candidate))}

      {BAND_FIELDS[block.block].some((candidate) => later(candidate.name)) && (
        <details data-testid={`more-${block.key}`}>
          <summary className="cursor-pointer text-xs font-medium text-muted hover:text-ink">{t("More options")}</summary>
          <div className="mt-3 space-y-4">
            {BAND_FIELDS[block.block].filter((candidate) => later(candidate.name)).map((candidate) => field(block, index, candidate))}
          </div>
        </details>
      )}
    </div>
  );

  const only = rows.length === 1 && !sideBySide ? rows[0] : undefined;

  return (
    <div data-testid={`panel-${band}`}>
      <div className="flex items-center gap-0.5 border-b border-line px-4 py-2.5">
        <h2 className="min-w-0 flex-1 truncate text-base font-semibold">{t(BAND_META[band].nav)}</h2>
        <button type="button" className={toolClass(false)} data-testid="move-band-up" disabled={before === undefined || band === 'ANNOUNCE'} aria-label={t("Move {section} earlier", { section: t(BAND_META[band].nav) })} onClick={() => before !== undefined && onMove(band, before)}>
          <Glyph d="M12 19V5M6 11l6-6 6 6" />
        </button>
        <button type="button" className={toolClass(false)} data-testid="move-band-down" disabled={after === undefined || band === 'ANNOUNCE'} aria-label={t("Move {section} later", { section: t(BAND_META[band].nav) })} onClick={() => after !== undefined && onMove(band, after)}>
          <Glyph d="M12 5v14M6 13l6 6 6-6" />
        </button>
        {authored !== null && rows.length > 0 && (
          <button
            type="button"
            className={toolClass(false)}
            data-testid="remove-band"
            aria-label={t("Remove this band")}
            title={t("Remove this band")}
            onClick={() => {
              change({ ...state, draft: state.draft.filter((block) => block.block !== band) });
              onDone();
            }}
          >
            <Glyph d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" />
          </button>
        )}
        <button type="button" className={toolClass(false)} data-testid="close-panel" aria-label={t("Back to the page's settings")} onClick={onDone}>
          <Glyph d="M6 6l12 12M18 6L6 18" />
        </button>
      </div>

      {authored !== null && (layouts.length > 0 || titled) && (
        <div role="tablist" aria-label={t(BAND_META[band].nav)} className="flex gap-4 border-b border-line px-4">
          {(['content', 'look'] as const).map((candidate) => (
            <button
              key={candidate}
              type="button"
              role="tab"
              aria-selected={tab === candidate}
              data-testid={`panel-tab-${candidate}`}
              className={cn(
                '-mb-px border-b-2 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2',
                tab === candidate ? 'border-accent font-semibold text-ink' : 'border-transparent text-muted hover:text-ink',
              )}
              onClick={() => setTab(candidate)}
            >
              {candidate === 'content' ? t("Content") : t("Look")}
            </button>
          ))}
        </div>
      )}

      <div className="space-y-4 px-4 py-4">
        {locale !== 'en' && (
          <p className="rounded-control bg-well px-3 py-2 text-xs text-muted" data-testid="panel-language">
            {t("Writing in {language}. A field left empty is read in English.", { language: LOCALE_NAMES[locale] })}
          </p>
        )}

        {band === 'PRICING' && (
          <p className="text-sm text-muted" data-testid="pricing-reads-the-catalogue">
            {t("The amounts are the product's public offers, read from the catalogue. Only the heading is written here.")}
          </p>
        )}

        {tab === 'content' && authored !== null && (
          <>
            {unfinished && (
              <p role="status" data-testid="band-unfinished" className="rounded-control border border-line bg-well px-3 py-2 text-sm">
                {sideBySide
                  ? t("Choose what each side shows — a demonstration or a picture. The page draws this band once both have one.")
                  : t("Choose what it shows — a demonstration or a picture. The page cannot be saved with this band empty.")}
              </p>
            )}

            {only !== undefined ? (
              form(only.block, only.index)
            ) : (
              <ul className="space-y-1" data-testid="panel-rows">
                {rows.map(({ block, index }, place) => {
                  const shown = sideBySide || open === block.key;
                  const first = BAND_FIELDS[block.block][0];
                  const name = first === undefined ? '' : (block.fields[first.name]?.en ?? '').trim();

                  return (
                    <li
                      key={block.key}
                      data-row={block.key}
                      className={cn('rounded-card border', shown ? 'border-line' : 'border-transparent hover:border-line')}
                    >
                      <div className="group flex items-center">
                        <button
                          type="button"
                          aria-expanded={shown}
                          data-testid={`row-${block.key}`}
                          className="min-w-0 flex-1 truncate px-3 py-2 text-left text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2"
                          onClick={() => setOpen(shown && !sideBySide ? null : block.key)}
                        >
                          <span className="mr-2 text-xs font-normal text-muted">{place + 1}</span>
                          {name === '' ? <span className="font-normal text-muted">{t("Not written yet")}</span> : name}
                        </button>

                        {/* Out of the way until the row is open or pointed at. */}
                        <span className={cn('flex', shown ? '' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100')}>
                          {rows.length > 1 && (
                            <>
                              <button type="button" className={toolClass(false, 'w-8')} disabled={place === 0} aria-label={t("Move this one earlier")} data-testid={`row-up-${block.key}`} onClick={() => swap(index, rows[place - 1]?.index ?? index)}>
                                <Glyph d="M12 19V5M6 11l6-6 6 6" />
                              </button>
                              <button type="button" className={toolClass(false, 'w-8')} disabled={place === rows.length - 1} aria-label={t("Move this one later")} data-testid={`row-down-${block.key}`} onClick={() => swap(index, rows[place + 1]?.index ?? index)}>
                                <Glyph d="M12 5v14M6 13l6 6 6-6" />
                              </button>
                            </>
                          )}
                          <button
                            type="button"
                            className={toolClass(false, 'w-8')}
                            aria-label={t("Remove")}
                            data-testid={`remove-${block.key}`}
                            onClick={() => change({ ...state, draft: state.draft.filter((_, gone) => gone !== index) })}
                          >
                            <Glyph d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" />
                          </button>
                        </span>
                      </div>

                      {shown && <div className="border-t border-line p-3">{form(block, index)}</div>}
                    </li>
                  );
                })}
              </ul>
            )}

            {rows.length === 0 && <p className="text-sm text-muted">{t("Nothing written yet")}</p>}

            {!full && (
              <button
                type="button"
                data-testid="add-row"
                className="text-sm font-medium text-accent hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                onClick={() => {
                  const row = blank(authored);

                  change({ ...state, draft: [...state.draft, row] });
                  setOpen(row.key);
                }}
              >
                {rows.length === 0 ? t("Write it") : `+ ${t("Add one")}`}
              </button>
            )}
          </>
        )}

        {(tab === 'look' || authored === null) && (
          <>
            {layouts.length > 0 && (
              <div className="space-y-2">
                <h3 className="text-sm font-medium">{t("Layout")}</h3>
                <div className="flex flex-wrap gap-2" role="group" aria-label={t("Layout")}>
                  {layouts.map((code, index) => {
                    // The first is the usual one, stored as no choice at all.
                    const on = index === 0 ? !layouts.includes(layout) || layout === code : layout === code;

                    return (
                      <button
                        key={code}
                        type="button"
                        aria-pressed={on}
                        data-testid={`layout-${code}`}
                        onClick={() => setHeading('layout', { en: index === 0 ? '' : code })}
                        className={cn(
                          'rounded-control border px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2',
                          on ? 'border-accent bg-accent-wash font-semibold text-ink' : 'border-line text-ink hover:bg-well',
                        )}
                      >
                        {t(LAYOUT_NAMES[code] ?? code)}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {titled && (
              <div className="space-y-4" data-heading={band}>
                <h3 className="text-sm font-medium">{t("Title above the band")}</h3>
                {headingSentencesOf(band).map((name) => (
                  <PanelField
                    key={name.name}
                    id={`heading-${band}-${name.name}`}
                    field={name}
                    value={heading[name.name] ?? EMPTY}
                    locale={locale}
                    onChange={(next) => setHeading(name.name, next, `heading-${band}-${name.name}`)}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Where a point sits on its picture, set by clicking it (2026-11-23).
 *
 * Two numbers nobody can guess — "how far across, out of a hundred" — are
 * what a point is stored as, and a click is how anybody would say it. The
 * other points are drawn too, faint, so a new one is not put on top of an
 * old one. Mapping a click to a share of the picture is presentation; the
 * numbers stay editable under *More options*.
 */
function HotspotPlacer({
  picture,
  points,
  current,
  onPlace,
}: {
  picture: string | null;
  points: readonly { key: string; x: string; y: string }[];
  current: string;
  onPlace: (x: string, y: string) => void;
}) {
  if (picture === null) {
    return <p className="rounded-control bg-well px-3 py-2 text-sm text-muted">{t("Add the picture on the first point; the others are placed on it.")}</p>;
  }

  return (
    <div className="space-y-1.5">
      <p className="text-sm font-medium">{t("Click the picture where this point goes")}</p>
      <div
        role="button"
        tabIndex={-1}
        data-testid={`place-${current}`}
        className="relative cursor-crosshair overflow-hidden rounded-control border border-line"
        onClick={(event) => {
          const box = event.currentTarget.getBoundingClientRect();
          const across = box.width === 0 ? 50 : ((event.clientX - box.left) / box.width) * 100;
          const down = box.height === 0 ? 50 : ((event.clientY - box.top) / box.height) * 100;
          const clamp = (value: number) => String(Math.round(Math.min(100, Math.max(0, value)) * 10) / 10);

          onPlace(clamp(across), clamp(down));
        }}
      >
        <img src={picture} alt="" className="block w-full select-none" draggable={false} />
        {points.map((point, index) =>
          point.x === '' || point.y === '' ? null : (
            <span
              key={point.key}
              aria-hidden="true"
              style={{ left: `${point.x}%`, top: `${point.y}%` }}
              className={cn(
                'pointer-events-none absolute flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-surface text-xs font-semibold',
                point.key === current ? 'bg-accent text-on-accent' : 'bg-surface/80 text-muted',
              )}
            >
              {index + 1}
            </span>
          ),
        )}
      </div>
    </div>
  );
}

/** The field of a band that holds the address of a live view. */
const EMBED = 'embed_url';

/**
 * The product's demonstrations, by name, where a band embeds one
 * (2026-11-22).
 *
 * The address field stays — a band may embed anything served over https,
 * and this list is only what the product itself answers for. Choosing one
 * writes its address into that field, which is then an ordinary field
 * again: saved, undone and reviewed like the rest.
 *
 * **Nothing is drawn where there is nothing to choose.** A product that
 * was never told where its demonstrations are, or whose host did not
 * answer, has an address field and no empty list beside it; where to say
 * so is the Products screen, which is where it is fixed.
 */
function DemonstrationPicker({
  id,
  found,
  address,
  onChoose,
}: {
  id: string;
  found: readonly ProductDemonstration[];
  address: string;
  onChoose: (address: string) => void;
}) {
  if (found.length === 0) {
    return null;
  }

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium">
        {t("Choose a demonstration")}
      </label>
      <select
        id={id}
        data-testid={`demonstration-${id}`}
        className={inputClass()}
        // The one whose address is in the field, so the list shows what
        // the band already embeds; anything else typed there is "another".
        value={found.some((candidate) => candidate.embed_url === address) ? address : ''}
        onChange={(event) => {
          if (event.target.value !== '') {
            onChoose(event.target.value);
          }
        }}
      >
        <option value="">{address === '' ? t("None") : t("Another address")}</option>
        {found.map((candidate) => (
          <option key={candidate.number} value={candidate.embed_url}>
            {`${String(candidate.number)} — ${candidate.name}`}
          </option>
        ))}
      </select>
    </div>
  );
}

function PanelSection({ title, aside, children }: { title: string; aside?: string | undefined; children: ReactNode }) {
  return (
    <section className="space-y-3 border-b border-line px-4 py-4">
      <h3 className="flex items-baseline gap-2 text-2xs font-semibold uppercase tracking-[0.08em] text-muted">
        {title}
        {aside !== undefined && <span className="font-normal normal-case tracking-normal">{aside}</span>}
      </h3>
      {children}
    </section>
  );
}

/** What the panel says with no band chosen: the page as a whole. */
function PagePanel({
  state,
  present,
  published,
  onMenu,
  onTemplate,
}: {
  state: StoryState;
  present: readonly BandKind[];
  published: boolean;
  onMenu: (hidden: boolean) => void;
  onTemplate: (template: StoryTemplate) => void;
}) {
  const left = untranslated(state);
  const hidden = menuIsHidden(state.headings.HEADLINE?.menu?.en);

  return (
    <div data-testid="panel-page">
      <div className="border-b border-line px-4 py-3">
        <h2 className="text-base font-semibold">{t("The page")}</h2>
        <p className="text-sm text-muted">
          {t("{count} bands", { count: String(present.length) })}
          {' · '}
          {published ? t("Published") : t("Draft — nobody outside can read it")}
        </p>
      </div>

      <PanelSection title={t("Menu under the headline")}>
        <p className="text-sm text-muted">
          {t("The bar naming the bands of the page, pinned as the reader scrolls. Drawn on a wide screen, once the page has three bands to name.")}
        </p>
        <div className="flex flex-wrap gap-2" role="group" aria-label={t("Menu under the headline")}>
          {[false, true].map((choice) => (
            <button
              key={String(choice)}
              type="button"
              aria-pressed={hidden === choice}
              data-testid={choice ? 'menu-hidden' : 'menu-shown'}
              onClick={() => onMenu(choice)}
              className={cn(
                'rounded-control border px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2',
                hidden === choice ? 'border-accent bg-accent-wash font-semibold text-ink' : 'border-line text-ink hover:bg-well',
              )}
            >
              {choice ? t("Hidden") : t("Shown")}
            </button>
          ))}
        </div>
      </PanelSection>

      <PanelSection title={t("Languages")}>
        <Languages left={left} />
      </PanelSection>

      <section className="border-b border-line px-4 py-3">
        {/* Closed: a template replaces the page, and is chosen once. */}
        <details data-testid="panel-templates">
          <summary className="cursor-pointer text-sm font-medium">{t("Start from a template")}</summary>
          <div className="mt-3 space-y-3">
            <p className="text-sm text-muted">
              {t("An order and sentences that say what goes where, to replace with your own. Only the English is filled, and nothing is saved until you press Save.")}
            </p>
            <TemplateChoice replaces={state.draft.length > 0} onChoose={onTemplate} />
          </div>
        </details>
      </section>
    </div>
  );
}

function Languages({ left }: { left: Record<string, number> }) {
  return (
    <ul className="space-y-1.5 text-sm" data-testid="editor-languages">
      {LOCALES.map((code) => {
        const missing = code === 'en' ? 0 : (left[code] ?? 0);

        return (
          <li key={code} className="flex items-baseline gap-2" data-language={code} data-missing={missing}>
            <span className="w-7 text-xs font-semibold uppercase text-muted">{code}</span>
            <span className="flex-1">{LOCALE_NAMES[code]}</span>
            <span className={missing === 0 ? 'text-xs text-muted' : 'text-xs font-medium text-warning'}>
              {code === 'en'
                ? t("what the page is written in")
                : missing === 0
                  ? t("complete")
                  : t("{count} read in English", { count: String(missing) })}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

const BAND_ABOUT: Record<AuthoredBandKind, string> = {
  HEADLINE: 'The promise, a sentence under it and a picture.',
  TRUST: 'Who already uses it: names or logos in a row.',
  PROBLEM: 'What it costs them today, in three blunt points.',
  STATS: 'Three or four figures, each with what it counts.',
  STEPS: 'How it works, one step after another.',
  FEATURE: 'What is included, each with an icon.',
  TOUR: 'Tabs, and one screenshot for each.',
  USE_CASE: 'Who it is for: before, and now.',
  QUOTE: 'What a customer said, and who they are.',
  PROOF: 'Screenshots with a caption: alternating, a grid or a carousel.',
  COMPARE: 'Before and after: two pictures under a slider, or two live views.',
  DEMO: 'The product itself, embedded in the page.',
  QUESTION: 'What is asked before every purchase, answered.',
  CTA: 'A last sentence and the button, to close the page.',
  ANNOUNCE: 'A line of news above the page, with a link and an end date.',
  VIDEO: 'A film of the product, played on a click.',
  VERSUS: 'This product against the other ways, criterion by criterion.',
  HOTSPOT: 'One picture, with points that explain what is where.',
};

/**
 * A band's shape, in a few grey boxes: `[x, y, width, height, dark]`.
 *
 * Drawn and not photographed, so it follows the theme and weighs nothing.
 * It says "a row of three" or "a wide picture" — enough to tell one band
 * from another at a glance, which fifteen names in a row did not.
 */
type Box = readonly [number, number, number, number, 0 | 1];

const BAND_SHAPE: Record<AuthoredBandKind, readonly Box[]> = {
  HEADLINE: [[8, 12, 50, 7, 1], [8, 24, 40, 4, 0], [8, 36, 22, 9, 1], [72, 8, 60, 42, 0]],
  TRUST: [[10, 24, 24, 9, 0], [42, 24, 24, 9, 0], [74, 24, 24, 9, 0], [106, 24, 24, 9, 0]],
  PROBLEM: [[10, 12, 10, 10, 1], [10, 28, 34, 4, 0], [54, 12, 10, 10, 1], [54, 28, 34, 4, 0], [98, 12, 10, 10, 1], [98, 28, 34, 4, 0]],
  STATS: [[10, 14, 30, 12, 1], [10, 32, 36, 4, 0], [54, 14, 30, 12, 1], [54, 32, 36, 4, 0], [98, 14, 30, 12, 1], [98, 32, 36, 4, 0]],
  STEPS: [[10, 10, 36, 20, 0], [10, 36, 26, 5, 1], [52, 10, 36, 20, 0], [52, 36, 26, 5, 1], [94, 10, 36, 20, 0], [94, 36, 26, 5, 1]],
  FEATURE: [[10, 10, 8, 8, 1], [22, 12, 24, 4, 0], [54, 10, 8, 8, 1], [66, 12, 24, 4, 0], [98, 10, 8, 8, 1], [110, 12, 24, 4, 0], [10, 34, 8, 8, 1], [22, 36, 24, 4, 0], [54, 34, 8, 8, 1], [66, 36, 24, 4, 0], [98, 34, 8, 8, 1], [110, 36, 24, 4, 0]],
  TOUR: [[10, 8, 26, 7, 1], [40, 8, 26, 7, 0], [70, 8, 26, 7, 0], [10, 20, 120, 30, 0]],
  USE_CASE: [[10, 10, 34, 6, 1], [10, 22, 52, 4, 0], [10, 30, 52, 4, 0], [76, 10, 34, 6, 1], [76, 22, 52, 4, 0], [76, 30, 52, 4, 0]],
  QUOTE: [[10, 10, 56, 24, 0], [74, 10, 56, 24, 0], [10, 40, 26, 5, 1], [74, 40, 26, 5, 1]],
  PROOF: [[10, 8, 62, 42, 0], [80, 16, 44, 5, 1], [80, 26, 50, 4, 0], [80, 34, 38, 4, 0]],
  COMPARE: [[10, 8, 60, 42, 0], [70, 8, 60, 42, 1]],
  DEMO: [[10, 8, 120, 36, 0], [44, 48, 52, 4, 1]],
  QUESTION: [[10, 10, 120, 8, 0], [10, 24, 120, 8, 0], [10, 38, 120, 8, 0]],
  CTA: [[30, 14, 80, 7, 1], [44, 26, 52, 4, 0], [56, 36, 28, 10, 1]],
  ANNOUNCE: [[0, 4, 140, 10, 1], [10, 22, 60, 6, 0], [10, 32, 90, 4, 0], [10, 40, 80, 4, 0]],
  VIDEO: [[20, 6, 100, 46, 0], [62, 21, 16, 16, 1]],
  VERSUS: [[10, 8, 40, 5, 0], [58, 8, 22, 5, 1], [88, 8, 22, 5, 0], [10, 20, 40, 5, 0], [64, 19, 8, 8, 1], [94, 19, 8, 8, 0], [10, 34, 40, 5, 0], [64, 33, 8, 8, 1], [94, 33, 8, 8, 0]],
  HOTSPOT: [[10, 6, 80, 46, 0], [30, 18, 7, 7, 1], [62, 32, 7, 7, 1], [98, 14, 32, 5, 1], [98, 24, 32, 4, 0]],
};

function Shape({ band }: { band: AuthoredBandKind }) {
  return (
    <svg viewBox="0 0 140 58" className="h-16 w-full rounded-control bg-well text-ink" aria-hidden="true">
      {BAND_SHAPE[band].map(([x, y, width, height, dark]) => (
        <rect key={`${String(x)}-${String(y)}`} x={x} y={y} width={width} height={height} rx="2" fill="currentColor" opacity={dark === 1 ? 0.45 : 0.18} />
      ))}
    </svg>
  );
}

/**
 * The bands a page can have, each with its shape and one line about it.
 *
 * Only what is not on the page yet can be added from here; a band that is
 * already there grows from its own panel, where its rows are.
 */
function Library({
  state,
  onAdd,
  onClose,
}: {
  state: StoryState;
  onAdd: (band: AuthoredBandKind) => void;
  onClose: () => void;
}) {
  return (
    <Modal title={t("Add a band")} testId="band-library" onClose={onClose} wide>
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {authoredBandsInOrder().map((band) => {
          const there = state.draft.some((block) => block.block === band);

          return (
            <li key={band}>
              <button
                type="button"
                disabled={there}
                data-testid={`add-${band}`}
                onClick={() => onAdd(band)}
                className={cn(
                  'flex h-full w-full flex-col gap-2 rounded-card border border-line bg-surface p-3 text-left',
                  'focus-visible:outline-2 focus-visible:outline-offset-2',
                  there ? 'opacity-50' : 'hover:border-accent',
                )}
              >
                <Shape band={band} />
                <span className="text-sm font-semibold">{t(BAND_META[band].nav)}</span>
                <span className="text-xs text-muted">{there ? t("Already on the page — it grows from its own panel.") : t(BAND_ABOUT[band])}</span>
              </button>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}

const CHANGE_NAMES: Record<StoryChange['what'], string> = {
  added: 'Added',
  removed: 'Removed',
  rewritten: 'Rewritten',
  layout: 'Laid out differently',
  order: 'The order of the bands changed',
};

/**
 * What a save would do, read before it is done.
 *
 * Not a confirmation for its own sake: on a published page the save is the
 * publication, and "which bands did I touch this afternoon?" is the
 * question the old Save button answered with a spinner.
 */
function Review({
  state,
  changes,
  published,
  onConfirm,
  onClose,
}: {
  state: StoryState;
  changes: readonly StoryChange[];
  published: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const missing = missingEnglish(state);

  return (
    <Modal title={t("Save the page")} testId="save-review" onClose={onClose}>
      <p className="text-sm text-muted">
        {published
          ? t("This page is published: saving changes what visitors read, at once.")
          : t("This page is a draft: saving shows it to nobody until it is published.")}
      </p>

      <section className="space-y-2">
        <h3 className="text-2xs font-semibold uppercase tracking-[0.08em] text-muted">{t("What changes")}</h3>
        <ul className="divide-y divide-line text-sm" data-testid="review-changes">
          {changes.map((change) => (
            <li key={`${change.band ?? 'page'}-${change.what}`} className="flex gap-3 py-2" data-change={change.what} data-band={change.band ?? ''}>
              {change.band !== null && <span className="w-40 shrink-0 font-medium">{t(BAND_META[change.band].nav)}</span>}
              <span className={change.band === null ? '' : 'text-muted'}>{t(CHANGE_NAMES[change.what])}</span>
            </li>
          ))}
        </ul>
      </section>

      {missing.length > 0 && (
        <section className="space-y-2" role="alert" data-testid="review-missing">
          <h3 className="text-2xs font-semibold uppercase tracking-[0.08em] text-danger">{t("Still to write, in English")}</h3>
          <ul className="space-y-1 text-sm">
            {missing.map((field) => (
              <li key={`${field.band}-${String(field.row)}-${field.label}`}>
                {t(BAND_META[field.band].nav)} {String(field.row)} — {t(field.label)}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="space-y-2">
        <h3 className="text-2xs font-semibold uppercase tracking-[0.08em] text-muted">{t("Languages")}</h3>
        <Languages left={untranslated(state)} />
      </section>

      <div className="flex flex-wrap justify-end gap-2 pt-2">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("Keep editing")}
        </Button>
        <Button type="button" data-testid="confirm-save" disabled={missing.length > 0} onClick={onConfirm}>
          {published ? t("Save and put online") : t("Save the draft")}
        </Button>
      </div>
    </Modal>
  );
}

function Modal({
  title,
  testId,
  wide = false,
  onClose,
  children,
}: {
  title: string;
  testId: string;
  wide?: boolean;
  onClose: () => void;
  children: ReactNode;
}) {
  const first = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    first.current?.focus();
  }, []);

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-inverse/50 p-4 md:p-10" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        data-testid={testId}
        className={cn('w-full space-y-4 rounded-card bg-surface p-5 shadow-float', wide ? 'max-w-4xl' : 'max-w-xl')}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center gap-3">
          <h2 className="flex-1 text-lg font-semibold">{title}</h2>
          <button ref={first} type="button" className={buttonClass('secondary')} onClick={onClose}>
            {t("Close")}
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
