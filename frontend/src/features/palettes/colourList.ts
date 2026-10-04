import type { FailingPair, ThemeDocument, ThemeMode } from './themeDocument';

/**
 * What the palette editor's colour list says about each token, and what its
 * filters keep (2026-10-05) — taken from Plan's palette screen.
 *
 * Pure: the editor renders, this decides.
 */

/** The design system's own colours, by token name — what `src/index.css` declares. */
export type DesignSystemColours = Readonly<Record<string, { readonly light?: string | undefined; readonly dark?: string | undefined }>>;

/**
 * A token's state, the gravest first: a pair it is in fails contrast, it
 * differs from the version opened (not saved yet), or it differs from the
 * design system's own colour. Null when none of the three.
 */
export type TokenState =
  | { readonly kind: 'contrast'; readonly pair: FailingPair }
  | { readonly kind: 'unsaved' }
  | { readonly kind: 'changed' }
  | null;

export type ColourFilter = 'all' | 'changed' | 'contrast';

const MODES: readonly ThemeMode[] = ['light', 'dark'];

function valuesOf(document: ThemeDocument): Map<string, { light: string; dark: string }> {
  return new Map(document.colors.flatMap((group) => group.tokens.map((token) => [token.name, token] as const)));
}

const same = (a: string | undefined, b: string | undefined) => a?.toLowerCase() === b?.toLowerCase();

/** The worst failing pair each token is part of, foreground or ground. */
export function worstPairs(failing: readonly FailingPair[]): ReadonlyMap<string, FailingPair> {
  const worst = new Map<string, FailingPair>();

  for (const pair of failing) {
    for (const name of [pair.foreground, pair.background]) {
      const known = worst.get(name);

      if (known === undefined || pair.ratio < known.ratio) {
        worst.set(name, pair);
      }
    }
  }

  return worst;
}

/** Whether a token differs from the version opened, or from the design system's own colour. */
export function differences(
  name: string,
  draft: ThemeDocument,
  saved: ThemeDocument,
  origin: DesignSystemColours,
): { readonly unsaved: boolean; readonly changed: boolean } {
  const now = valuesOf(draft).get(name);
  const before = valuesOf(saved).get(name);
  const designed = origin[name];

  return {
    unsaved: MODES.some((mode) => !same(now?.[mode], before?.[mode])),
    // A token the design system does not have is not "changed" from it: there
    // is nothing to have changed from.
    changed: designed !== undefined && MODES.some((mode) => designed[mode] !== undefined && !same(now?.[mode], designed[mode])),
  };
}

export function tokenState(
  name: string,
  draft: ThemeDocument,
  saved: ThemeDocument,
  origin: DesignSystemColours,
  worst: ReadonlyMap<string, FailingPair>,
): TokenState {
  const pair = worst.get(name);

  if (pair !== undefined) {
    return { kind: 'contrast', pair };
  }

  const { unsaved, changed } = differences(name, draft, saved, origin);

  return unsaved ? { kind: 'unsaved' } : changed ? { kind: 'changed' } : null;
}

/** Whether a token is kept by the filter and the search; the search reads its name, its variable and its role. */
export function keeps(
  filter: ColourFilter,
  search: string,
  token: { readonly name: string; readonly variable: string },
  role: string | undefined,
  facts: { readonly failing: boolean; readonly changed: boolean },
): boolean {
  // "Changed" is changed from either reference — unsaved, or away from the
  // design system — whatever its contrast says.
  const byFilter = filter === 'all' || (filter === 'contrast' ? facts.failing : facts.changed);
  const needle = search.trim().toLowerCase();

  return byFilter && (needle === '' || `${token.name} ${token.variable} ${role ?? ''}`.toLowerCase().includes(needle));
}
