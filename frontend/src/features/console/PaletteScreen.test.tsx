import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import stylesheet from '@/index.css?raw';

import { PaletteScreen } from './PaletteScreen';
import { readPalette } from './palette';

describe('PaletteScreen', () => {
  it('draws a card for every colour the stylesheet defines', () => {
    render(<PaletteScreen />);

    for (const token of readPalette(stylesheet).groups.flatMap((group) => group.tokens)) {
      const card = screen.getByTestId(`token-${token.utility}`);

      expect(within(card).getByText(token.variable)).toBeTruthy();
      expect(within(card).getByText(`index.css:${token.light?.line ?? 0}`)).toBeTruthy();
    }
  });

  it('scopes each mood board to its theme with the values the file gives it', () => {
    render(<PaletteScreen />);
    const { scope } = readPalette(stylesheet);

    for (const theme of ['light', 'dark'] as const) {
      const board = screen.getByTestId(`mood-board-${theme}`);

      expect(board.style.getPropertyValue('--color-canvas')).toBe(scope[theme]['--ds-canvas']);
      expect(board.style.getPropertyValue('--color-accent')).toBe(scope[theme]['--ds-accent']);
    }
  });

  it('measures the pairs it lists, and every one clears AA in both themes', () => {
    render(<PaletteScreen />);

    const row = screen.getByTestId('pair-ink-canvas');

    expect(within(row).getAllByText(/^\d+\.\d\d:1$/)).toHaveLength(2);
    expect(within(row).getAllByText('AA')).toHaveLength(2);
  });
});
