import { describe, expect, it } from 'vitest';

import { readPalette } from '@/features/console/palette';
import stylesheet from '@/index.css?raw';

import { PALETTES } from './fixtures';
import { TOKEN_ROLES } from './tokenRoles';

/**
 * Every colour says what it paints (2026-10-05). The design system's list is
 * the stylesheet's, so the roles are held to it both ways: a token added to
 * `index.css` without a role fails here, and so does a role for a token gone.
 */
describe('TOKEN_ROLES', () => {
  const tokens = readPalette(stylesheet).groups.flatMap((group) => group.tokens.map((token) => token.utility));

  it('names a role for every colour of the design system, and for nothing else', () => {
    expect(Object.keys(TOKEN_ROLES).sort()).toEqual([...tokens].sort());
  });

  it('covers every token the seeded palettes carry', () => {
    for (const palette of PALETTES) {
      for (const token of palette.document.colors.flatMap((group) => group.tokens)) {
        expect(TOKEN_ROLES[token.name], `${palette.name}: ${token.name}`).toBeDefined();
      }
    }
  });
});
