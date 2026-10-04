import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * Every semantic class must have a token behind it.
 *
 * This exists because one did not. `shadow-raise-strong` was written in four
 * components — the command palette's input, two buttons in the context bar, the
 * drawing canvas and a colour swatch — and there is no `--shadow-raise-strong`.
 * Tailwind does not warn about a utility it cannot resolve: it emits nothing, so
 * the class sat in the markup, the elements rendered flat, and every review read
 * the name and assumed the shadow.
 *
 * That is the whole failure mode of a semantic palette. `bg-red-500` is checked
 * by Tailwind's own scale, but `bg-danger-wash` is only as real as this file
 * makes it — a typo, a token renamed on one side, or a name somebody expected to
 * exist all fail exactly this silently.
 *
 * **So the check is narrow on purpose.** It only looks at classes whose root is
 * a token this application defines, which is what makes it cheap: a Tailwind
 * utility like `text-sm` or `rounded-full` is none of its business, and a class
 * called `bg-accent-washed` is, because `accent` is ours.
 *
 * **And it is narrow in one way worth knowing.** A typo in the *root* —
 * `bg-surfacee` rather than `bg-surface` — slips through, because the root is
 * what tells this file whether a class is its business at all. Widening it means
 * enumerating Tailwind's own palette and keyword values and re-checking that list
 * on every upgrade, to catch a mistake the eye already catches in review. The
 * mistake this exists for is the one the eye does *not* catch: a plausible suffix
 * on a real token, read as meaningful by every reviewer and resolved by nobody.
 */

const CSS = join(__dirname, '..', 'index.css');
const SRC = join(__dirname, '..');

/** The names `@theme` actually defines, by the utility prefix that consumes them. */
function tokens(): { colour: Set<string>; shadow: Set<string>; radius: Set<string> } {
  const theme = /@theme \{([\s\S]*?)\n\}/.exec(readFileSync(CSS, 'utf8'));

  // A missing `@theme` block would make every assertion below vacuously pass,
  // which is the one way this file could lie.
  expect(theme).not.toBeNull();
  const body = theme?.[1] ?? '';

  const named = (kind: string): Set<string> =>
    new Set([...body.matchAll(new RegExp(`--${kind}-([a-z0-9-]+):`, 'g'))].map((m) => m[1] ?? ''));

  return { colour: named('color'), shadow: named('shadow'), radius: named('radius') };
}

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);

    if (statSync(path).isDirectory()) {
      return sources(path);
    }

    return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
  });
}

/**
 * A source file without its comments. Several of them quote the classes they
 * replaced — `tone.ts` explains itself with `bg-emerald-100` — and a history
 * lesson is not a use.
 */
function code(path: string): string {
  return readFileSync(path, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

/** `bg-accent-wash/40` → `accent-wash`; the opacity suffix is Tailwind's, not ours. */
const CLASSES =
  /\b(?:bg|text|border|ring|outline|fill|stroke|divide|shadow|rounded)-([a-z][a-z0-9-]*)(?:\/\d+)?\b/g;

describe('every semantic class', () => {
  it('names a token that exists', () => {
    const { colour, shadow, radius } = tokens();
    const defined = new Set([...colour, ...shadow, ...radius]);

    // The roots we own. A class is ours to check when its first segment is one
    // of these — `accent-wash` is checked, `slate-200` is Tailwind's problem.
    const roots = new Set([...defined].map((name) => name.split('-')[0] ?? ''));

    const dangling: string[] = [];

    for (const path of sources(SRC)) {
      for (const [, name = ''] of readFileSync(path, 'utf8').matchAll(CLASSES)) {
        if (defined.has(name)) {
          continue;
        }

        if (roots.has(name.split('-')[0] ?? '')) {
          dangling.push(`${path.replace(SRC, 'src')}: ${name}`);
        }
      }
    }

    // Named rather than counted: the failure has to say which class and where,
    // or somebody reads "expected 1 to be 0" and goes looking by hand.
    expect([...new Set(dangling)]).toEqual([]);
  });

  it('is never a colour that only exists in one theme', () => {
    // The defect this catches shipped in the design system's own frame: the
    // status strip and the phone bottom bar carried `bg-white` with nothing
    // beside it, so both rendered as white bars across the bottom of a dark
    // page. The command palette and the More sheet were the same, which made
    // the palette a white card over a dark application.
    //
    // **The contrast scan cannot see this.** Dark text on a white bar passes
    // WCAG comfortably; it is the *theme* that is wrong, not the legibility, and
    // axe has no opinion about that. Nor does the type checker, nor eslint —
    // `bg-white` is a perfectly real class. So it is checked here.
    //
    // A scrim used to be the exception — `bg-black/40` behind a dialog — and
    // is not any more: it is `bg-scrim`, because a 40% black over a ground that
    // is already black separates nothing, and only a token can differ by theme.
    const offenders: string[] = [];

    for (const path of sources(SRC)) {
      for (const [whole = ''] of code(path).matchAll(/\b(?:bg|text|border)-(?:white|black)(?:\/\d+)?\b/g)) {
        offenders.push(`${path.replace(SRC, 'src')}: ${whole}`);
      }
    }

    expect([...new Set(offenders)]).toEqual([]);
  });

  it('is never a bare tone name sitting in a class string', () => {
    // This one is written from a regression it did not catch. The codemod that
    // introduced `tone.ts` rewrote the colour literal `'bg-amber-100 …'` to
    // `'warning'` everywhere it appeared — including five places where the
    // literal was an inline `className` fragment rather than a tone a function
    // returned. The result was `class="rounded px-1.5 py-0.5 text-xs warning"`:
    // a class that resolves to nothing, on five badges that silently lost their
    // colour, one of them the connection badge in the frame.
    //
    // The check above could not see it, because `warning` carries no utility
    // prefix — it looks like nothing rather than like a broken something. So
    // this looks for the opposite shape: a tone name inside a template literal
    // that is building a class string.
    //
    // `pill(closed ? 'neutral' : 'warning')` is the correct form and is not
    // matched: the tone names are arguments there, not interpolated text.
    const tones = '(?:success|warning|danger|info|neutral)';
    const inClassString = new RegExp(
      // `${ … 'warning' … }` inside a backtick string — the interpolation that
      // ends up concatenated into a className.
      String.raw`\$\{[^}]*'${tones}'[^}]*\}`,
      'gs',
    );

    const offenders: string[] = [];

    for (const path of sources(SRC)) {
      const text = readFileSync(path, 'utf8');

      for (const [whole = ''] of text.matchAll(inClassString)) {
        // A tone passed to one of the tone helpers inside an interpolation is
        // the intended use: `${pill('success')} gap-2`.
        if (/\b(?:pill|panel|notice|ink|dot)\(/.test(whole)) {
          continue;
        }

        offenders.push(`${path.replace(SRC, 'src')}: ${whole.replace(/\s+/g, ' ').slice(0, 70)}`);
      }
    }

    expect([...new Set(offenders)]).toEqual([]);
  });

  it('is always a token, never a colour written in a component', () => {
    // Every colour on screen comes from `index.css`, so that one file is the
    // whole palette — what `/console/palette` shows and what a developer
    // edits. Three ways around that, each of which renders fine and drifts:
    //
    //   - a Tailwind palette step, `bg-emerald-100`, which no theme redefines;
    //   - an arbitrary value, `bg-[#0b6e99]`, which is the hex with extra steps;
    //   - a colour literal handed to `style`, the same thing again.
    //
    // A brand colour a tenant typed is data, not style, and reaches the page
    // through `--ds-accent`; the branding screen's preview of what is being
    // typed is the one place a literal is the subject rather than the styling.
    const palette =
      'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    const forbidden = [
      new RegExp(String.raw`\b(?:bg|text|border|ring|outline|fill|stroke|divide|from|via|to|decoration|caret|accent|placeholder)-(?:${palette})-\d{2,3}\b`, 'g'),
      /\b(?:bg|text|border|ring|outline|fill|stroke|divide|from|via|to|decoration|caret|placeholder)-\[(?:#|rgb|hsl|oklch|color)[^\]]*\]/g,
      /['"`]#[0-9a-fA-F]{3,8}['"`]|\b(?:rgba?|hsla?|oklch)\(/g,
    ];
    const allowed = ['src/features/branding/BrandingScreen.tsx'];
    const offenders: string[] = [];

    for (const path of sources(SRC)) {
      const name = path.replace(SRC, 'src');

      if (allowed.includes(name)) {
        continue;
      }

      for (const pattern of forbidden) {
        for (const [whole = ''] of code(path).matchAll(pattern)) {
          offenders.push(`${name}: ${whole}`);
        }
      }
    }

    expect([...new Set(offenders)]).toEqual([]);
  });

  it('never picks a different token for the dark theme', () => {
    // A token already carries both themes; that is what it is for. Choosing
    // another one under `dark:` is choosing from outside the palette, and it is
    // how four lists came to hover on `bg-inverse` — the *light* ground in the
    // dark theme — leaving light ink on a near-white row: a row that vanished
    // under the pointer, in a migration that read `neutral-900` as "inverse".
    const offenders: string[] = [];

    for (const path of sources(SRC)) {
      for (const [whole = ''] of code(path).matchAll(/\bdark:(?:[a-z-]+:)*(?:bg|text|border|ring|outline|fill|stroke|divide)-[a-z]/g)) {
        offenders.push(`${path.replace(SRC, 'src')}: ${whole}`);
      }
    }

    expect([...new Set(offenders)]).toEqual([]);
  });

  it('defines a wash for every tone that has an ink, and the reverse', () => {
    // `bg-success-wash text-success` is the pair a status badge needs. A tone
    // with only half of it defined is a badge nobody can build.
    const { colour } = tokens();

    for (const tone of ['success', 'warning', 'danger', 'info']) {
      expect(colour.has(tone), `${tone} ink`).toBe(true);
      expect(colour.has(`${tone}-wash`), `${tone} wash`).toBe(true);
    }
  });
});
