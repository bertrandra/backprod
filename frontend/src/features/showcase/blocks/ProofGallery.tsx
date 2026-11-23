import { useEffect, useRef, useState } from 'react';

import { t } from '@/i18n';

import type { ProofRow, ShowcaseRow } from './content';

/**
 * The screenshots as a wall, each opened large on a click (2026-11-23).
 *
 * A mosaic in columns, so pictures of different shapes sit together
 * without being cropped to one. Opened, a picture fills the window with
 * its caption, and the arrow keys and two buttons move through the others;
 * Escape, the close button or a click outside closes it, and focus goes
 * back to the picture that opened it.
 */
export function ProofGallery({ rows }: { rows: readonly ShowcaseRow<ProofRow>[] }) {
  const pictured = rows.filter((row) => row.image !== null);
  const [open, setOpen] = useState<number | null>(null);
  const opener = useRef<HTMLButtonElement | null>(null);
  const close = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open === null) {
      return;
    }

    close.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(null);
      } else if (event.key === 'ArrowRight') {
        setOpen((at) => (at === null ? at : (at + 1) % pictured.length));
      } else if (event.key === 'ArrowLeft') {
        setOpen((at) => (at === null ? at : (at - 1 + pictured.length) % pictured.length));
      }
    };

    document.addEventListener('keydown', onKey);

    return () => document.removeEventListener('keydown', onKey);
  }, [open, pictured.length]);

  const shown = open === null ? null : pictured[open];

  return (
    <>
      <ul data-layout="gallery" className="columns-1 gap-4 sm:columns-2 lg:columns-3">
        {pictured.map((row, index) => (
          <li key={row.id} data-proof={row.id} className="mb-4 break-inside-avoid">
            <button
              type="button"
              className="group block w-full overflow-hidden rounded-card border border-line text-left focus-visible:outline-2 focus-visible:outline-offset-2"
              onClick={(event) => {
                opener.current = event.currentTarget;
                setOpen(index);
              }}
            >
              <img
                src={row.image?.url}
                alt={row.image?.alt ?? ''}
                loading="lazy"
                className="block w-full transition-transform duration-300 group-hover:scale-[1.02]"
              />
              <span className="block bg-surface px-3 py-2 text-sm text-muted">{row.content.caption}</span>
            </button>
          </li>
        ))}
      </ul>

      {shown !== undefined && shown !== null && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={shown.content.caption}
          data-testid="proof-lightbox"
          className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-inverse/90 p-4"
          onClick={() => {
            setOpen(null);
            opener.current?.focus();
          }}
        >
          <img
            src={shown.image?.url}
            alt={shown.image?.alt ?? ''}
            className="max-h-[80vh] max-w-full rounded-card object-contain"
            onClick={(event) => event.stopPropagation()}
          />
          <p className="max-w-prose text-center text-sm text-on-inverse">{shown.content.caption}</p>
          <div className="flex gap-2" onClick={(event) => event.stopPropagation()}>
            {pictured.length > 1 && (
              <button type="button" className={LIGHTBOX_BUTTON} onClick={() => setOpen(((open ?? 0) - 1 + pictured.length) % pictured.length)}>
                {t("Previous")}
              </button>
            )}
            <button
              ref={close}
              type="button"
              className={LIGHTBOX_BUTTON}
              onClick={() => {
                setOpen(null);
                opener.current?.focus();
              }}
            >
              {t("Close")}
            </button>
            {pictured.length > 1 && (
              <button type="button" className={LIGHTBOX_BUTTON} onClick={() => setOpen(((open ?? 0) + 1) % pictured.length)}>
                {t("Next")}
              </button>
            )}
          </div>
        </div>
      )}
    </>
  );
}

const LIGHTBOX_BUTTON =
  'rounded-control border border-on-inverse/40 px-4 py-2 text-sm font-medium text-on-inverse hover:bg-on-inverse/10 focus-visible:outline-2 focus-visible:outline-offset-2';
