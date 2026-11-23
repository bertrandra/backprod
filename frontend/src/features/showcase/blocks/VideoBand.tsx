import { useState } from 'react';

import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ShowcaseRow, VideoRow } from './content';
import { BAND_META } from './meta';

/**
 * A film of the product, played on a click (2026-11-23).
 *
 * **Nothing from the player until somebody asks.** A YouTube frame loads
 * the player, its scripts and its cookies the moment it is drawn, for every
 * visitor, most of whom will scroll past. So the band draws a poster — the
 * operator's picture, or a dark frame — and puts the player in only when
 * the play button is pressed, playing at once. YouTube is played from its
 * no-cookie origin, Vimeo with "do not track".
 *
 * Which two origins may be framed is the Content-Security-Policy's
 * decision too: the server refuses any other host, and the bundle names
 * these two in `frame-src`.
 */
export function VideoBand({ row, heading }: { row: ShowcaseRow<VideoRow> | null; heading?: BandHeading | undefined }) {
  const [playing, setPlaying] = useState(false);

  if (row === null) {
    return null;
  }

  const player = playerOf(row.content.videoUrl);

  return (
    <ShowcaseBand
      id={BAND_META.VIDEO.anchor}
      surface={BAND_META.VIDEO.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Watch it")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-video"
    >
      <figure className="space-y-3">
        <div className="relative aspect-video w-full overflow-hidden rounded-card border border-line bg-inverse">
          {playing && player !== null ? (
            <iframe
              src={player}
              title={row.content.caption}
              className="absolute inset-0 h-full w-full border-0"
              allow="autoplay; fullscreen; picture-in-picture"
              allowFullScreen
              data-testid="video-player"
            />
          ) : (
            <button
              type="button"
              data-testid="video-play"
              disabled={player === null}
              onClick={() => setPlaying(true)}
              className="group absolute inset-0 flex items-center justify-center focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              {row.image !== null && (
                <img src={row.image.url} alt={row.image.alt} className="absolute inset-0 h-full w-full object-cover" />
              )}
              <span className="relative flex size-20 items-center justify-center rounded-full bg-surface/90 text-ink shadow-float transition-transform group-hover:scale-105">
                <svg width="30" height="30" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M8 5v14l11-7z" fill="currentColor" />
                </svg>
              </span>
              <span className="sr-only">{t("Play the video")}</span>
            </button>
          )}
        </div>
        <figcaption className="text-base text-muted">{row.content.caption}</figcaption>
      </figure>
    </ShowcaseBand>
  );
}

/**
 * The player's address for a page's address, or null where it is neither.
 *
 * Shaping an address the operator pasted into the one the player serves is
 * presentation: the same film, at the address that plays it in a frame.
 */
export function playerOf(address: string): string | null {
  let url: URL;

  try {
    url = new URL(address);
  } catch {
    return null;
  }

  const host = url.hostname.replace(/^(www|m)\./, '');
  const id = /^[A-Za-z0-9_-]{6,20}$/;

  if (host === 'youtu.be' || host === 'youtube.com') {
    const named =
      host === 'youtu.be'
        ? url.pathname.slice(1)
        : (url.searchParams.get('v') ?? url.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)/)?.[1] ?? '');

    return id.test(named) ? `https://www.youtube-nocookie.com/embed/${named}?autoplay=1&rel=0` : null;
  }

  if (host === 'vimeo.com' || host === 'player.vimeo.com') {
    const named = url.pathname.match(/(\d{5,12})(?:\/|$)/)?.[1] ?? '';

    return named === '' ? null : `https://player.vimeo.com/video/${named}?autoplay=1&dnt=1`;
  }

  return null;
}
