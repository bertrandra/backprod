import { describe, expect, it } from 'vitest';

import { differences, keeps, tokenState, worstPairs } from './colourList';
import { PALETTES } from './fixtures';
import { failingPairs, recolour, type ThemeDocument } from './themeDocument';

const PETROL = PALETTES[0]?.document as ThemeDocument;
const valueOf = (name: string, mode: 'light' | 'dark') =>
  PETROL.colors.flatMap((group) => group.tokens).find((token) => token.name === name)?.[mode];
const AS_SAVED = Object.fromEntries(
  PETROL.colors.flatMap((group) => group.tokens).map((token) => [token.name, { light: token.light, dark: token.dark }]),
);

describe('the state a colour row says', () => {
  it('says nothing about a colour as saved and as the design system has it', () => {
    expect(tokenState('accent', PETROL, PETROL, AS_SAVED, worstPairs(failingPairs(PETROL)))).toBeNull();
  });

  it('says "not saved" before "changed", and contrast before both', () => {
    const draft = recolour(PETROL, 'accent', 'light', '#123456');
    expect(tokenState('accent', draft, PETROL, AS_SAVED, new Map())).toEqual({ kind: 'unsaved' });

    // Saved, but away from the design system.
    expect(tokenState('accent', draft, draft, AS_SAVED, new Map())).toEqual({ kind: 'changed' });

    // Ink the colour of the canvas: every pair it is in fails, and that is said first.
    const unreadable = recolour(PETROL, 'ink', 'light', valueOf('canvas', 'light') ?? '');
    const state = tokenState('ink', unreadable, PETROL, AS_SAVED, worstPairs(failingPairs(unreadable)));
    expect(state?.kind).toBe('contrast');
    expect(state?.kind === 'contrast' ? state.pair.ratio : 0).toBeLessThan(1.5);
  });

  it('compares codes without regard to case', () => {
    const upper = recolour(PETROL, 'accent', 'light', (valueOf('accent', 'light') ?? '').toUpperCase());
    expect(differences('accent', upper, PETROL, AS_SAVED)).toEqual({ unsaved: false, changed: false });
  });

  it('is never "changed" from a token the design system does not have', () => {
    const draft = recolour(PETROL, 'accent', 'light', '#123456');
    expect(differences('accent', draft, draft, {}).changed).toBe(false);
  });
});

describe('what the filters and the search keep', () => {
  const token = { name: 'accent-wash', variable: '--ds-accent-wash' };
  const plain = { failing: false, changed: false };

  it('keeps all, the changed, or the failing', () => {
    expect(keeps('all', '', token, undefined, plain)).toBe(true);
    expect(keeps('changed', '', token, undefined, plain)).toBe(false);
    expect(keeps('changed', '', token, undefined, { failing: true, changed: false })).toBe(false);
    expect(keeps('contrast', '', token, undefined, { failing: true, changed: false })).toBe(true);
  });

  it('searches the name, the variable and the role, ignoring case', () => {
    expect(keeps('all', 'WASH', token, undefined, plain)).toBe(true);
    expect(keeps('all', '--ds-accent', token, undefined, plain)).toBe(true);
    expect(keeps('all', 'selection', token, 'A light accent ground: selection', plain)).toBe(true);
    expect(keeps('all', 'toast', token, 'A light accent ground: selection', plain)).toBe(false);
  });
});
