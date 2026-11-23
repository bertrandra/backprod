import type { AnnounceRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * A line of news, above the whole page (2026-11-23).
 *
 * Above the headline whatever its place in the order, because news is read
 * first or not at all — the page draws it there. Past its last day the
 * public read leaves it out, so nothing here looks at a date.
 *
 * A link to `#place` stays on the page; any other opens beside it, since a
 * visitor who follows the news should not lose the page they were reading.
 */
export function AnnounceBand({ row }: { row: ShowcaseRow<AnnounceRow> | null }) {
  if (row === null) {
    return null;
  }

  const { text, linkLabel, linkUrl } = row.content;
  const here = linkUrl?.startsWith('#') === true;

  return (
    <aside
      id={BAND_META.ANNOUNCE.anchor}
      data-band={BAND_META.ANNOUNCE.anchor}
      data-testid="showcase-announce"
      className="bg-inverse px-4 py-2.5 text-center text-sm text-on-inverse"
    >
      <span>{text}</span>
      {linkUrl !== null && (
        <a
          href={linkUrl}
          className="ml-3 font-semibold text-on-inverse underline underline-offset-2"
          {...(here ? {} : { target: '_blank', rel: 'noopener noreferrer' })}
        >
          {linkLabel ?? linkUrl}
        </a>
      )}
    </aside>
  );
}
