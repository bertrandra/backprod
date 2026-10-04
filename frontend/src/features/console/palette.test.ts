import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { contrast, readPalette } from './palette';

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
