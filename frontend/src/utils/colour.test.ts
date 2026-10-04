import { describe, expect, it } from 'vitest';

import { fromHsl, fromRgb, isHex, shades, toHsl, toRgb } from './colour';

describe('colour', () => {
  it('reads a code into channels and back', () => {
    expect(toRgb('#0b6e99')).toEqual({ r: 11, g: 110, b: 153 });
    expect(fromRgb({ r: 11, g: 110, b: 153 })).toBe('#0b6e99');
    expect(fromRgb({ r: 300, g: -4, b: 15.6 })).toBe('#ff0010');
  });

  it('round-trips through hue, saturation and lightness within a rounding step', () => {
    for (const hex of ['#0b6e99', '#a52131', '#f5f7f9', '#000000', '#ffffff', '#1d7a4e']) {
      const back = toRgb(fromHsl(toHsl(hex) ?? { h: 0, s: 0, l: 0 }));
      const original = toRgb(hex);

      expect(Math.abs((back?.r ?? 0) - (original?.r ?? 0))).toBeLessThanOrEqual(3);
      expect(Math.abs((back?.g ?? 0) - (original?.g ?? 0))).toBeLessThanOrEqual(3);
      expect(Math.abs((back?.b ?? 0) - (original?.b ?? 0))).toBeLessThanOrEqual(3);
    }

    expect(toHsl('#ff0000')).toEqual({ h: 0, s: 100, l: 50 });
    expect(fromHsl({ h: 120, s: 100, l: 50 })).toBe('#00ff00');
  });

  it('offers nine shades of a hue, light to dark, and none of a value it cannot read', () => {
    const steps = shades('#0b6e99');

    expect(steps).toHaveLength(9);
    expect(steps.every(isHex)).toBe(true);
    expect(toHsl(steps[0] ?? '')?.l).toBeGreaterThan(toHsl(steps[8] ?? '')?.l ?? 100);
    expect(shades('rgb(0 0 0 / 0.6)')).toEqual([]);
  });
});
