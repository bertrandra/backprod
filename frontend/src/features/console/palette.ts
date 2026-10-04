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
 *   - **notes** are the comment written directly above a `--ds-*` value;
 *   - **fonts** are the `--font-*` stacks of `@theme`, and the **type scale**
 *     its `--text-*` steps with their line heights and tracking.
 *
 * Adding a token is therefore one edit, in the CSS, and the screen follows.
 */

export type Theme = 'light' | 'dark';

// Measured where every theme is measured, not here: the console and an
// organisation's theme editor ask the same question of the same values.
export { contrast } from '@/utils/contrast';

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

export interface PaletteFont {
  /** `sans`, `mono`: the `--font-*` suffix, used as `font-sans`. */
  readonly role: string;
  readonly variable: string;
  /** The first family of the stack, unquoted — the one the design system ships. */
  readonly family: string;
  readonly stack: string;
  readonly line: number;
}

export interface PaletteTextStep {
  /** `xs`, `2xl`, `display-lg`: used as `text-xs`. */
  readonly name: string;
  readonly variable: string;
  readonly size: string;
  readonly lineHeight: string | undefined;
  readonly letterSpacing: string | undefined;
  readonly line: number;
}

export interface Palette {
  readonly groups: readonly PaletteGroup[];
  readonly fonts: readonly PaletteFont[];
  readonly typeScale: readonly PaletteTextStep[];
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
  const fonts: PaletteFont[] = [];
  const steps = new Map<string, { size?: string; lineHeight?: string; letterSpacing?: string; line: number }>();
  let previousWasColour = false;

  for (const declaration of theme === undefined ? [] : declarations(theme)) {
    const colour = /^color-(.+)$/.exec(declaration.name);
    const variable = /^var\(--(ds-[a-z0-9-]+)\)$/.exec(declaration.value);
    const font = /^font-([a-z0-9-]+)$/.exec(declaration.name);
    const text = /^text-([a-z0-9]+(?:-[a-z0-9]+)*?)(?:--(line-height|letter-spacing))?$/.exec(declaration.name);

    if (declaration.name.startsWith('shadow-')) {
      themeScope[`--${declaration.name}`] = declaration.value;
    }

    if (font?.[1] !== undefined) {
      const first = declaration.value.split(',')[0]?.trim().replace(/^['"]|['"]$/g, '') ?? '';
      fonts.push({ role: font[1], variable: `--${declaration.name}`, family: first, stack: declaration.value, line: declaration.line });
    }

    if (text?.[1] !== undefined) {
      const step = steps.get(text[1]) ?? { line: declaration.line };

      if (text[2] === 'line-height') {
        step.lineHeight = declaration.value;
      } else if (text[2] === 'letter-spacing') {
        step.letterSpacing = declaration.value;
      } else {
        step.size = declaration.value;
      }

      steps.set(text[1], step);
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

  // A step is its size; a line height or tracking with no size beside it is
  // not a step anybody can set text in.
  const typeScale = [...steps].flatMap(([name, step]) =>
    step.size === undefined
      ? []
      : [{ name, variable: `--text-${name}`, size: step.size, lineHeight: step.lineHeight, letterSpacing: step.letterSpacing, line: step.line }],
  );

  return {
    groups,
    fonts,
    typeScale,
    unmapped,
    scope: { light: scope(light, new Map()), dark: scope(dark, light) },
  };
}

/**
 * The palette as the document the platform saves (`ThemeDocument` in the
 * contract): colours in their groups with both themes' values, fonts, type
 * scale. A colour with no value in a theme is left out rather than sent empty
 * — the server would refuse the whole document for it, and rightly.
 */
export function themeDocument(palette: Palette) {
  return {
    format: 1 as const,
    colors: palette.groups
      .map((group) => ({
        group: group.title,
        tokens: group.tokens.flatMap((token) =>
          token.light === undefined || token.dark === undefined
            ? []
            : [{ name: token.utility, variable: token.variable, light: token.light.value, dark: token.dark.value }],
        ),
      }))
      .filter((group) => group.tokens.length > 0),
    fonts: palette.fonts.map(({ role, variable, family, stack }) => ({ role, variable, family, stack })),
    type_scale: palette.typeScale.map((step) => ({
      name: step.name,
      variable: step.variable,
      size: step.size,
      line_height: step.lineHeight ?? null,
      letter_spacing: step.letterSpacing ?? null,
    })),
  };
}
