import { describe, expect, it } from 'vitest';

import { PALETTE_NAMES, PALETTES } from './fixtures';
import { failingPairs, recolour, themeProperties, type ThemeDocument } from './themeDocument';

function template(name: string): ThemeDocument {
  const found = PALETTES.find((palette) => palette.name === name);

  if (found === undefined) {
    throw new Error(`No palette ${name}.`);
  }

  return found.document;
}

describe('themeProperties', () => {
  it('sets each colour in its mode, and the fonts and type scale in both', () => {
    const forest = template('forest-ledger');
    const light = themeProperties(forest, 'light');
    const dark = themeProperties(forest, 'dark');
    const accent = forest.colors.flatMap((group) => group.tokens).find((token) => token.name === 'accent');

    expect(light['--ds-accent']).toBe(accent?.light);
    expect(dark['--ds-accent']).toBe(accent?.dark);
    expect(light['--font-sans']).toBe(forest.fonts[0]?.stack);
    expect(light['--text-xl']).toBe('1.25rem');
    expect(light['--text-xl--line-height']).toBe('1.65rem');
    expect(light['--color-accent']).toBeUndefined();
    expect(themeProperties(forest, 'light', true)['--color-accent']).toBe(accent?.light);
  });

  it('sets nothing outside the design system, whatever the document says', () => {
    const hostile: ThemeDocument = {
      ...template('petrol-classic'),
      colors: [
        {
          group: 'Hostile',
          tokens: [
            { name: 'a', variable: '--tw-ring-color', light: '#000000', dark: '#000000' },
            { name: 'b', variable: '--ds-canvas', light: '#fff; } body { display: none', dark: '#000000' },
            { name: 'c', variable: '--ds-ink', light: '#101010', dark: '#efefef' },
          ],
        },
      ],
      fonts: [{ role: 'sans', variable: '--font-sans', family: 'x', stack: 'x; color: red' }],
    };

    const properties = themeProperties(hostile, 'light');

    expect(properties['--tw-ring-color']).toBeUndefined();
    expect(properties['--ds-canvas']).toBeUndefined();
    expect(properties['--font-sans']).toBeUndefined();
    expect(properties['--ds-ink']).toBe('#101010');
  });
});

describe('every seeded palette', () => {
  it.each(PALETTE_NAMES)('%s clears AA on every pair, in both modes', (name) => {
    expect(failingPairs(template(name))).toEqual([]);
  });
});

describe('failingPairs and recolour', () => {
  it('names the pair a change breaks, in the mode it breaks it', () => {
    const petrol = template('petrol-classic');
    const canvas = petrol.colors[0]?.tokens.find((token) => token.name === 'canvas')?.light ?? '';
    const broken = recolour(petrol, 'muted', 'light', canvas);

    expect(failingPairs(broken).map((pair) => `${pair.mode} ${pair.foreground}/${pair.background}`)).toContain(
      'light muted/canvas',
    );
    expect(failingPairs(broken).every((pair) => pair.mode === 'light')).toBe(true);
    // Everything else as it was.
    expect(recolour(petrol, 'muted', 'light', canvas).fonts).toBe(petrol.fonts);
  });
});

describe('verdict', () => {
  it('is WCAG’s level for text', async () => {
    const { verdict } = await import('./themeDocument');

    expect(verdict(7)).toBe('AAA');
    expect(verdict(4.5)).toBe('AA');
    expect(verdict(3)).toBe('AA large');
    expect(verdict(2.9)).toBe('fail');
  });
});
