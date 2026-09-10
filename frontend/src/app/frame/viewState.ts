/**
 * The screen state that lives in the URL.
 *
 * ui-spec.md §4.3: every state that matters — the filter, the tab, the selected
 * record, the open panel — is in the URL. A state that only exists in memory
 * cannot be shared, bookmarked, or reported in a bug, and "it looked like this
 * on my machine" is where an afternoon goes.
 *
 * Validated rather than trusted. A URL is user input: `?limit=all` and
 * `?tab=<script>` both arrive here, and a screen that read them raw would
 * either crash or render them. Anything unparseable falls back to the default,
 * because a bad link should open the page rather than break it.
 */

import { useSearch } from '@tanstack/react-router';

export interface ViewState {
  /** Free-text filter. */
  readonly q?: string;
  /** Which tab, for screens that have them. */
  readonly tab?: string;
  /** The selected record, which is what the inspector shows. */
  readonly selected?: string;
  /** Whether the inspector is open — distinct from something being selected. */
  readonly panel?: boolean;
  /** Page size, bounded the way the API bounds it. */
  readonly limit?: number;
  readonly offset?: number;
}

/** Matches the API's own bounds, so the UI cannot ask for what it will refuse. */
export const LIMIT_MIN = 1;
export const LIMIT_MAX = 200;

const IDENTIFIER = /^[A-Za-z0-9._:-]{1,128}$/;

function text(value: unknown, max: number): string | undefined {
  if (typeof value !== 'string') {
    return undefined;
  }

  const trimmed = value.trim();

  return trimmed === '' ? undefined : trimmed.slice(0, max);
}

function identifier(value: unknown): string | undefined {
  return typeof value === 'string' && IDENTIFIER.test(value) ? value : undefined;
}

function bounded(value: unknown, min: number, max: number): number | undefined {
  const n = typeof value === 'number' ? value : Number(value);

  if (!Number.isInteger(n) || n < min || n > max) {
    // Out of range falls back to the default here, unlike the API, which
    // refuses. The difference is deliberate: a wrong query string is usually a
    // stale bookmark, and a person following a link wants the page.
    return undefined;
  }

  return n;
}

export function parseViewState(search: Record<string, unknown>): ViewState {
  const q = text(search.q, 200);
  const tab = identifier(search.tab);
  const selected = identifier(search.selected);
  const limit = bounded(search.limit, LIMIT_MIN, LIMIT_MAX);
  const offset = bounded(search.offset, 0, 100_000);

  // Spread conditionally rather than assigning into a bag and asserting the
  // result: `exactOptionalPropertyTypes` is on, so an absent key and a key set
  // to undefined are different things, and this way the return type is checked
  // rather than claimed.
  return {
    ...(q !== undefined && { q }),
    ...(tab !== undefined && { tab }),
    ...(selected !== undefined && { selected }),
    // Only a real `true` opens it, so `?panel=whatever` does not.
    ...((search.panel === true || search.panel === 'true') && { panel: true }),
    ...(limit !== undefined && { limit }),
    ...(offset !== undefined && { offset }),
  };
}

/**
 * The current view state, typed.
 *
 * `useSearch({ strict: false })` answers `any`: the route tree is built at
 * runtime (see `router.tsx`), so there is no generated route union for the
 * router to infer a search type from. Left alone that `any` would spread into
 * every screen that reads a filter or a selection, and `selected.toUpperCase()`
 * on a number would compile.
 *
 * So it is narrowed once, here, through the same parser the routes validate
 * with. Parsing an already-parsed object returns the same thing, which is what
 * makes doing it twice safe rather than merely cheap.
 */
export function useViewState(): ViewState {
  const search: unknown = useSearch({ strict: false });

  return parseViewState(
    typeof search === 'object' && search !== null ? (search as Record<string, unknown>) : {},
  );
}
