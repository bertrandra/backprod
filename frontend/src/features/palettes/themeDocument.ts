import type { Schemas } from '@/api/client';
import { contrast } from '@/utils/contrast';

/**
 * What a theme document means on screen (2026-10-04).
 *
 * A document names variables and values; this module turns it into the
 * custom properties a page sets, and measures it. Nothing here touches the
 * DOM — {@see useTenantTheme} applies, the editor previews, and both ask this
 * module what to set.
 */
export type ThemeDocument = Schemas['ThemeDocument'];
export type ThemeMode = 'light' | 'dark';

/**
 * Only these variables are ever set from a document. The server already holds
 * every name and value to CSS's shape; this is the same rule at the place a
 * value meets the page, so a document can repaint the design system and do
 * nothing else — not a `--tw-*` internal, not a property that is not ours.
 */
const VARIABLE = /^--(?:ds|font|text)-[a-z0-9-]+$/;
const VALUE = /^[#a-zA-Z0-9(),.%/ +-]{1,100}$/;
const STACK = /^[a-zA-Z0-9 ,'"._-]{1,500}$/;

/**
 * The pairs text is set in, foreground on ground — measured in the console's
 * Pairings table and in the theme editor, and the same pairs the server's
 * test holds every template to.
 */
export const PAIRS: readonly (readonly [string, string])[] = [
  ['ink', 'canvas'],
  ['ink', 'surface'],
  ['ink', 'well'],
  ['ink', 'accent-wash'],
  ['muted', 'canvas'],
  ['muted', 'surface'],
  ['muted', 'well'],
  ['subtle', 'canvas'],
  ['subtle', 'surface'],
  ['accent', 'surface'],
  ['on-accent', 'accent'],
  ['accent-strong', 'accent-wash'],
  ['on-inverse', 'inverse'],
  ['success', 'success-wash'],
  ['warning', 'warning-wash'],
  ['danger', 'danger-wash'],
  ['info', 'info-wash'],
];

/**
 * The custom properties a document sets in one mode: each colour's `--ds-*`
 * in that mode, and — the same in both — the font stacks and the type scale.
 *
 * `utilities` adds `--color-*` beside each `--ds-*`. A page-wide theme does
 * not need it, because `@theme` resolves `--color-x: var(--ds-x)` on the root
 * where the theme is set; a preview scoped to one element does, because there
 * the root has already resolved it.
 */
export function themeProperties(document: ThemeDocument, mode: ThemeMode, utilities = false): Record<string, string> {
  const properties: Record<string, string> = {};

  for (const group of document.colors) {
    for (const token of group.tokens) {
      const value = token[mode];

      if (VARIABLE.test(token.variable) && VALUE.test(value)) {
        properties[token.variable] = value;

        if (utilities) {
          properties[`--color-${token.name}`] = value;
        }
      }
    }
  }

  for (const font of document.fonts) {
    if (VARIABLE.test(font.variable) && STACK.test(font.stack)) {
      properties[font.variable] = font.stack;
    }
  }

  for (const step of document.type_scale) {
    if (!VARIABLE.test(step.variable) || !VALUE.test(step.size)) {
      continue;
    }

    properties[step.variable] = step.size;

    if (step.line_height !== null && VALUE.test(step.line_height)) {
      properties[`${step.variable}--line-height`] = step.line_height;
    }

    if (step.letter_spacing !== null && VALUE.test(step.letter_spacing)) {
      properties[`${step.variable}--letter-spacing`] = step.letter_spacing;
    }
  }

  return properties;
}

export interface FailingPair {
  readonly mode: ThemeMode;
  readonly foreground: string;
  readonly background: string;
  readonly ratio: number;
}

/** The pairs under WCAG AA's 4.5:1 for text, in either mode. */
export function failingPairs(document: ThemeDocument): readonly FailingPair[] {
  const values = new Map(document.colors.flatMap((group) => group.tokens.map((token) => [token.name, token] as const)));
  const failing: FailingPair[] = [];

  for (const mode of ['light', 'dark'] as const) {
    for (const [foreground, background] of PAIRS) {
      const fg = values.get(foreground)?.[mode];
      const bg = values.get(background)?.[mode];
      const ratio = fg === undefined || bg === undefined ? undefined : contrast(fg, bg);

      if (ratio !== undefined && ratio < 4.5) {
        failing.push({ mode, foreground, background, ratio });
      }
    }
  }

  return failing;
}

/** The document with one colour changed in one mode; everything else as it was. */
export function recolour(document: ThemeDocument, name: string, mode: ThemeMode, value: string): ThemeDocument {
  return {
    ...document,
    colors: document.colors.map((group) => ({
      ...group,
      tokens: group.tokens.map((token) => (token.name === name ? { ...token, [mode]: value } : token)),
    })),
  };
}

/** Whether a value is one the server will take — checked before a save, not instead of it. */
export function isCssValue(value: string): boolean {
  return VALUE.test(value);
}

export type Verdict = 'AAA' | 'AA' | 'AA large' | 'fail';

/**
 * WCAG's levels for text: 7:1 is AAA, 4.5:1 AA, 3:1 AA for large text only.
 * Every pair this platform sets body text in has to reach AA.
 */
export function verdict(ratio: number): Verdict {
  return ratio >= 7 ? 'AAA' : ratio >= 4.5 ? 'AA' : ratio >= 3 ? 'AA large' : 'fail';
}

/** The ratio of two of the document's colours in one mode, if both are readable. */
export function pairRatio(document: ThemeDocument, foreground: string, background: string, mode: ThemeMode): number | undefined {
  const tokens = document.colors.flatMap((group) => group.tokens);
  const fg = tokens.find((token) => token.name === foreground)?.[mode];
  const bg = tokens.find((token) => token.name === background)?.[mode];

  return fg === undefined || bg === undefined ? undefined : contrast(fg, bg);
}

/**
 * The stylesheet a document amounts to: what the shell sets, written out —
 * `:root` with the light values, the fonts and the type scale, and the dark
 * values inside the colour-scheme query. For a developer to read or paste;
 * the shell itself sets properties one by one and never parses this text.
 */
export function themeCss(document: ThemeDocument): string {
  const block = (properties: Record<string, string>, indent: string) =>
    Object.entries(properties)
      .map(([name, value]) => `${indent}${name}: ${value};`)
      .join('\n');

  const light = themeProperties(document, 'light');
  const dark: Record<string, string> = {};

  for (const group of document.colors) {
    for (const token of group.tokens) {
      const value = themeProperties(document, 'dark')[token.variable];

      if (value !== undefined) {
        dark[token.variable] = value;
      }
    }
  }

  return `:root {\n${block(light, '  ')}\n}\n\n@media (prefers-color-scheme: dark) {\n  :root {\n${block(dark, '    ')}\n  }\n}\n`;
}
