import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import type { ThemeDocument } from './themeDocument';

/** The fifteen palettes the migrations seed, read from their source. Tests only. */
export const PALETTE_NAMES = [
  'petrol-classic',
  'forest-ledger',
  'terracotta-studio',
  'midnight-indigo',
  'graphite-compact',
  'monochrome',
  'psychedelic',
  'high-contrast',
  'pastel-dream',
  'ocean-depth',
  'sunset-glow',
  'neon-night',
  'nordic-frost',
  'vintage-sepia',
  'royal-velvet',
] as const;

export const AT = '2026-10-04T09:00:00+00:00';

export const PALETTES = PALETTE_NAMES.map((name) => ({
  name,
  updated_at: AT,
  document: JSON.parse(readFileSync(join(__dirname, '..', '..', '..', '..', 'docs', 'themes', `${name}.json`), 'utf8')) as ThemeDocument,
}));
