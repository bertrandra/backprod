import type { ReactElement } from 'react';

import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import stylesheet from '@/index.css?raw';
import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { PaletteScreen } from './PaletteScreen';
import { readPalette, themeDocument } from './palette';

const DOCUMENT = themeDocument(readPalette(stylesheet));
const SAVED_AT = '2026-10-04T09:00:00+00:00';

/** No palette named `default`: where every deployment starts. */
const NOTHING_SAVED: Stubs = {
  'GET /api/v1/staff/palettes': { data: { palettes: [] } },
};

function render(ui: ReactElement, stubs: Stubs = NOTHING_SAVED) {
  return renderWith(ui, stubClient(stubs), { product: null });
}

describe('PaletteScreen', () => {
  it('shows every font family and every step of the type scale the stylesheet defines', () => {
    render(<PaletteScreen />);
    const palette = readPalette(stylesheet);

    for (const font of palette.fonts) {
      const card = screen.getByTestId(`font-${font.role}`);

      expect(within(card).getByText(font.family)).toBeTruthy();
      expect(within(card).getByText(font.stack)).toBeTruthy();
    }

    for (const step of palette.typeScale) {
      const row = screen.getByTestId(`text-${step.name}`);
      const sample = within(row).getByText('One seat, billed monthly');

      expect(sample.style.fontSize).toBe(step.size);
    }
  });

  it('saves the document it read as the palette default, and says none had that name before', async () => {
    let saved = false;
    const { client, requests } = recordingClient({
      // The list answers what the server holds: nothing named default, then
      // the palette the save wrote.
      'GET /api/v1/staff/palettes': () => ({
        data: { palettes: saved ? [{ name: 'default', updated_at: SAVED_AT, document: DOCUMENT }] : [] },
      }),
      'PUT /api/v1/staff/palettes/{name}': () => {
        saved = true;

        return { data: { palette: { name: 'default', updated_at: SAVED_AT, document: DOCUMENT } } };
      },
    });
    renderWith(<PaletteScreen />, client, { product: null });

    await waitFor(() => expect(screen.getByTestId('palette-state').textContent).toBe('No palette has this name yet.'));
    fireEvent.click(screen.getByTestId('save-as-palette'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));
    const put = requests.find((r) => r.method === 'PUT');

    expect(put?.pathParams).toEqual({ name: 'default' });
    expect(put?.body).toEqual({ document: DOCUMENT });
    await waitFor(() =>
      expect(screen.getByTestId('palette-state').textContent).toContain('Saved, and the same as the stylesheet.'),
    );
  });

  it('says when the palette saved under the name is not the stylesheet any more', async () => {
    render(<PaletteScreen />, {
      'GET /api/v1/staff/palettes': {
        data: { palettes: [{ name: 'default', updated_at: SAVED_AT, document: { ...DOCUMENT, fonts: [] } }] },
      },
    });

    await waitFor(() =>
      expect(screen.getByTestId('palette-state').textContent).toContain('Saved, and different from the stylesheet.'),
    );
  });

  it('saves under another name when one is typed, and refuses one the path cannot carry', async () => {
    const { client, requests } = recordingClient({
      ...NOTHING_SAVED,
      'PUT /api/v1/staff/palettes/{name}': { data: { palette: { name: 'winter', updated_at: SAVED_AT, document: DOCUMENT } } },
    });
    renderWith(<PaletteScreen />, client, { product: null });

    await waitFor(() => expect(screen.getByTestId('palette-state').textContent).not.toBe(''));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Winter Theme' } });
    expect(screen.getByTestId<HTMLButtonElement>('save-as-palette').disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'winter' } });
    fireEvent.click(screen.getByTestId('save-as-palette'));

    await waitFor(() => expect(requests.find((r) => r.method === 'PUT')?.pathParams).toEqual({ name: 'winter' }));
  });

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
