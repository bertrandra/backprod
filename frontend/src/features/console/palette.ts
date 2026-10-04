/**
 * The design system's palette, read out of the stylesheet that defines it.
 *
 * `/console/palette` shows the palette, and it would be worthless if it showed
 * a copy: a list of colours typed into a component is right on the day it is
 * written and wrong the first time somebody edits `index.css`, with nothing to
 * say which of the two is the truth. So the screen reads the file — imported as
 * text — and everything it draws comes from here:
 *
 *   - **groups** are the runs of `--color-*` in `@theme`, each titled by the
 *     first sentence of the comment above it and explained by the rest;
 *   - **values** are the `--ds-*` declarations of the light `:root` and of the
 *     dark one inside `prefers-color-scheme`, with the line each sits on;
 *   - **notes** are the comment written directly above a `--ds-*` value.
 *
 * Adding a token is therefore one edit, in the CSS, and the screen follows.
 */

export type Theme = 'light' | 'dark';

export interface PaletteValue {
  /** As written: `#0b6e99`, `rgb(…)`, whatever the file says. */
  readonly value: string;
  /** 1-based, in `src/index.css`, so a developer can go straight to it. */
  readonly line: number;
  readonly note: string | undefined;
}

export interface PaletteToken {
  /** The utility root: `accent-wash`, used as `bg-accent-wash`, `text-accent-wash`… */
  readonly utility: string;
  /** The runtime variable a theme redefines: `--ds-accent-wash`. */
  readonly variable: string;
  readonly line: number;
  readonly light: PaletteValue | undefined;
  readonly dark: PaletteValue | undefined;
}

export interface PaletteGroup {
  readonly title: string;
  readonly description: string;
  readonly tokens: readonly PaletteToken[];
}

export interface Palette {
  readonly groups: readonly PaletteGroup[];
  /** `--ds-*` values no utility maps — defined, and unreachable from a class. */
  readonly unmapped: readonly { readonly variable: string; readonly light: PaletteValue | undefined; readonly dark: PaletteValue | undefined }[];
  /** Every custom property each theme sets, for scoping a preview to it. */
  readonly scope: Readonly<Record<Theme, Readonly<Record<string, string>>>>;
}

interface Block {
  readonly body: string;
  /** The line `body` starts on, 1-based. */
  readonly line: number;
}

function block(css: string, pattern: RegExp): Block | undefined {
  const match = pattern.exec(css);

  if (match?.[1] === undefined) {
    return undefined;
  }

  const start = match.index + match[0].indexOf(match[1]);

  return { body: match[1], line: css.slice(0, start).split('\n').length };
}

/** A comment's text, without its delimiters or the indentation of its lines. */
function prose(comment: string): string {
  return comment
    .replace(/^\/\*+/, '')
    .replace(/\*+\/$/, '')
    .split('\n')
    .map((line) => line.replace(/^\s*\*?\s?/, '').trim())
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim();
}

interface Declaration {
  readonly name: string;
  readonly value: string;
  readonly line: number;
  /** The comment immediately above, if nothing else stood between them. */
  readonly comment: string | undefined;
}

/** The custom properties of a block, in order, each with what stood above it. */
function declarations({ body, line: first }: Block): Declaration[] {
  const found: Declaration[] = [];
  const pattern = /\/\*[\s\S]*?\*\/|--([a-z0-9-]+)\s*:\s*([^;]+);/g;
  let comment: string | undefined;

  for (const match of body.matchAll(pattern)) {
    const [text, name, value] = match;

    if (text.startsWith('/*')) {
      comment = prose(text);
    } else if (name !== undefined && value !== undefined) {
      const line = first + body.slice(0, match.index).split('\n').length - 1;
      found.push({ name, value: value.trim(), line, comment });
      comment = undefined;
    }
  }

  return found;
}

function values(source: Block | undefined): Map<string, PaletteValue> {
  const map = new Map<string, PaletteValue>();

  for (const declaration of source === undefined ? [] : declarations(source)) {
    map.set(declaration.name, { value: declaration.value, line: declaration.line, note: declaration.comment });
  }

  return map;
}

/** A run with no comment above it is still drawn; it just has nothing to be called. */
const UNTITLED = '—';

/** "Surfaces, in levels. Depth is…" → title "Surfaces, in levels", the rest below it. */
function heading(comment: string | undefined): { title: string; description: string } {
  if (comment === undefined || comment === '') {
    return { title: UNTITLED, description: '' };
  }

  const end = /[.:](\s|$)/.exec(comment);

  if (end === null) {
    return { title: comment, description: '' };
  }

  return { title: comment.slice(0, end.index), description: comment.slice(end.index + 1).trim() };
}

export function readPalette(css: string): Palette {
  const theme = block(css, /@theme \{([\s\S]*?)\n\}/);
  // The light theme is the first `:root` written at the top level; the dark one
  // is the `:root` inside the colour-scheme query.
  const light = values(block(css, /^:root \{([\s\S]*?)\n\}/m));
  const dark = values(block(css, /@media \(prefers-color-scheme: dark\) \{\s*:root \{([\s\S]*?)\n {2}\}/));

  const groups: { title: string; description: string; tokens: PaletteToken[] }[] = [];
  const mapped = new Set<string>();
  const themeScope: Record<string, string> = {};
  let previousWasColour = false;

  for (const declaration of theme === undefined ? [] : declarations(theme)) {
    const colour = /^color-(.+)$/.exec(declaration.name);
    const variable = /^var\(--(ds-[a-z0-9-]+)\)$/.exec(declaration.value);

    if (declaration.name.startsWith('shadow-')) {
      themeScope[`--${declaration.name}`] = declaration.value;
    }

    if (colour?.[1] === undefined) {
      previousWasColour = false;
      continue;
    }

    // A comment opens a group; a blank line alone does not, so a run that the
    // file splits for breathing room stays one group on the screen.
    const current = groups.at(-1);

    if (current === undefined || !previousWasColour || declaration.comment !== undefined) {
      groups.push({ ...heading(declaration.comment), tokens: [] });
    }

    const name = variable?.[1];

    if (name !== undefined) {
      mapped.add(name);
    }

    groups.at(-1)?.tokens.push({
      utility: colour[1],
      variable: name === undefined ? declaration.value : `--${name}`,
      line: declaration.line,
      light: name === undefined ? undefined : light.get(name),
      dark: name === undefined ? undefined : (dark.get(name) ?? light.get(name)),
    });
    previousWasColour = true;
  }

  const scope = (own: Map<string, PaletteValue>, inherit: Map<string, PaletteValue>): Record<string, string> => {
    const properties: Record<string, string> = { ...themeScope };

    for (const [name, value] of [...inherit, ...own]) {
      properties[`--${name}`] = value.value;
    }

    // `@theme` resolves `--color-x: var(--ds-x)` where it is declared, on the
    // root, so redefining `--ds-x` on an element below it changes nothing. A
    // preview of one theme has to set the utilities' own variables as well.
    for (const group of groups) {
      for (const token of group.tokens) {
        const value = own.get(token.variable.slice(2)) ?? inherit.get(token.variable.slice(2));

        if (value !== undefined) {
          properties[`--color-${token.utility}`] = value.value;
        }
      }
    }

    return properties;
  };

  const unmapped = [...light.keys()]
    .filter((name) => name.startsWith('ds-') && !mapped.has(name))
    .map((name) => ({ variable: `--${name}`, light: light.get(name), dark: dark.get(name) ?? light.get(name) }));

  return {
    groups,
    unmapped,
    scope: { light: scope(light, new Map()), dark: scope(dark, light) },
  };
}

/**
 * WCAG relative luminance contrast between two `#rgb`/`#rrggbb` colours, or
 * undefined for anything else — a value this cannot read is shown as unknown
 * rather than guessed at.
 */
export function contrast(foreground: string, background: string): number | undefined {
  const luminance = (hex: string): number | undefined => {
    const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(hex);
    const long = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    const parts = long !== null ? long.slice(1) : short?.slice(1).map((c) => c + c);

    if (parts === undefined) {
      return undefined;
    }

    const [r = 0, g = 0, b = 0] = parts.map((part) => {
      const channel = parseInt(part, 16) / 255;

      return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };

  const a = luminance(foreground);
  const b = luminance(background);

  if (a === undefined || b === undefined) {
    return undefined;
  }

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
