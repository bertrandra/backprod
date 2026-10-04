import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { contrast, readPalette, themeDocument } from './palette';

const CSS = readFileSync(join(__dirname, '..', '..', 'index.css'), 'utf8');

describe('readPalette', () => {
  it('draws every colour utility the stylesheet defines, and no other', () => {
    const palette = readPalette(CSS);
    const theme = /@theme \{([\s\S]*?)\n\}/.exec(CSS)?.[1] ?? '';
    const defined = [...theme.matchAll(/--color-([a-z0-9-]+):/g)].map((m) => m[1]);

    expect(palette.groups.flatMap((group) => group.tokens.map((token) => token.utility))).toEqual(defined);
  });

  it('titles each group by the first sentence of its comment', () => {
    const titles = readPalette(CSS).groups.map((group) => group.title);

    expect(titles).toEqual([
      'Surfaces, in levels',
      'Ink, in three weights',
      'Lines',
      'Accent',
      'Inverse',
      'Scrim',
      'Meaning, not decoration',
      'Neither good nor bad',
    ]);
  });

  it('reads both themes, with the line each value sits on', () => {
    const accent = readPalette(CSS).groups.flatMap((group) => group.tokens).find((token) => token.utility === 'accent');
    const lines = CSS.split('\n');

    expect(accent?.variable).toBe('--ds-accent');
    expect(accent?.light?.value).toMatch(/^#/);
    expect(accent?.dark?.value).toMatch(/^#/);
    expect(accent?.light?.value).not.toBe(accent?.dark?.value);
    expect(lines[(accent?.light?.line ?? 0) - 1]).toContain(`--ds-accent: ${accent?.light?.value};`);
    expect(lines[(accent?.dark?.line ?? 0) - 1]).toContain(`--ds-accent: ${accent?.dark?.value};`);
    expect(accent?.light?.note).toMatch(/^Petrol blue\./);
  });

  it('follows an edit to the stylesheet, which is the whole point of reading it', () => {
    const edited = CSS.replace('--ds-accent: #0b6e99;', '--ds-accent: #c0ffee;').replace(
      '--color-on-accent: var(--ds-on-accent);',
      '--color-on-accent: var(--ds-on-accent);\n  --color-glow: var(--ds-glow);',
    ).replace('--ds-on-accent: #ffffff;', '--ds-on-accent: #ffffff;\n  --ds-glow: #fff3b0;\n  --ds-orphan: #123456;');

    const palette = readPalette(edited);
    const tokens = palette.groups.flatMap((group) => group.tokens);

    expect(tokens.find((token) => token.utility === 'accent')?.light?.value).toBe('#c0ffee');
    // A new utility joins the group it was written in; a dark theme that does
    // not redefine it inherits the light value, as the cascade would.
    expect(palette.groups.find((group) => group.title === 'Accent')?.tokens.map((token) => token.utility)).toContain('glow');
    expect(tokens.find((token) => token.utility === 'glow')?.dark?.value).toBe('#fff3b0');
    expect(palette.unmapped.map((entry) => entry.variable)).toEqual(['--ds-orphan']);
  });

  it('scopes a theme by setting the utilities’ own variables, not only the --ds ones', () => {
    const { scope } = readPalette(CSS);

    expect(scope.dark['--color-canvas']).toBe(scope.dark['--ds-canvas']);
    expect(scope.light['--color-canvas']).toBe(scope.light['--ds-canvas']);
    expect(scope.light['--color-canvas']).not.toBe(scope.dark['--color-canvas']);
    expect(scope.dark['--shadow-float']).toMatch(/rgb\(0 0 0/);
    expect(scope.light['--shadow-float']).toMatch(/rgb\(13 20 32/);
  });
});

describe('fonts and type scale', () => {
  it('reads every --font-* of @theme, with the family the stack ships first', () => {
    const { fonts } = readPalette(CSS);

    expect(fonts.map((font) => font.role)).toEqual(['sans', 'mono']);
    expect(fonts[0]?.family).toBe('Geist Variable');
    expect(fonts[1]?.family).toBe('Geist Mono Variable');
    expect(fonts[0]?.stack).toMatch(/sans-serif$/);
  });

  it('reads every --text-* step with its line height and tracking, and nothing else as a step', () => {
    const { typeScale } = readPalette(CSS);
    const theme = /@theme \{([\s\S]*?)\n\}/.exec(CSS)?.[1] ?? '';
    const sizes = [...theme.matchAll(/--text-([a-z0-9-]+?):/g)].map((m) => m[1]).filter((name) => !name?.includes('--'));

    expect(typeScale.map((step) => step.name)).toEqual(sizes);
    expect(typeScale.find((step) => step.name === 'xl')).toMatchObject({
      size: '1.25rem',
      lineHeight: '1.65rem',
      letterSpacing: '-0.012em',
    });
    expect(typeScale.find((step) => step.name === 'base')?.letterSpacing).toBeUndefined();
    expect(typeScale.find((step) => step.name === 'display-xl')?.size).toBe('4.5rem');
  });
});

describe('themeDocument', () => {
  it('is the palette in the contract’s shape, every value read from the stylesheet', () => {
    const palette = readPalette(CSS);
    const document = themeDocument(palette);

    expect(document.format).toBe(1);
    expect(document.colors.map((group) => group.group)).toEqual(palette.groups.map((group) => group.title));
    expect(document.colors[0]?.tokens[0]).toEqual({
      name: 'canvas',
      variable: '--ds-canvas',
      light: palette.scope.light['--ds-canvas'],
      dark: palette.scope.dark['--ds-canvas'],
    });
    expect(document.fonts[0]).toEqual({
      role: 'sans',
      variable: '--font-sans',
      family: 'Geist Variable',
      stack: palette.fonts[0]?.stack,
    });
    expect(document.type_scale.find((step) => step.name === 'base')).toEqual({
      name: 'base',
      variable: '--text-base',
      size: '0.875rem',
      line_height: '1.4375rem',
      letter_spacing: null,
    });
  });

  it('holds only values the server accepts: no quote, semicolon, brace or angle bracket', () => {
    const document = themeDocument(readPalette(CSS));
    const value = /^[#a-zA-Z0-9(),.%/ +-]{1,100}$/;

    for (const token of document.colors.flatMap((group) => group.tokens)) {
      expect(token.light, token.name).toMatch(value);
      expect(token.dark, token.name).toMatch(value);
    }

    for (const step of document.type_scale) {
      expect(step.size).toMatch(value);
    }
  });
});

describe('contrast', () => {
  it('is the WCAG ratio', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
    expect(contrast('#fff', '#fff')).toBeCloseTo(1, 5);
    expect(contrast('#767676', '#ffffff')).toBeCloseTo(4.54, 2);
  });

  it('says nothing about a value it cannot read', () => {
    expect(contrast('rgb(0 0 0)', '#ffffff')).toBeUndefined();
  });
});
