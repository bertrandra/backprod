import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith } from '@/test-utils';

import { PaletteChoiceScreen } from './PaletteChoiceScreen';
import { PALETTES } from './fixtures';

describe('PaletteChoiceScreen', () => {
  it('offers the platform’s palettes, says which one is worn, and edits none', async () => {
    renderWith(
      <PaletteChoiceScreen />,
      recordingClient({ 'GET /api/v1/tenant/palettes': { data: { palettes: PALETTES, selected: 'forest-ledger' } } }).client,
    );

    const forest = await screen.findByTestId('palette-forest-ledger');

    expect(within(forest).getByText('In use')).toBeTruthy();
    expect(screen.getByTestId('palette-worn').textContent).toBe('Your members see Forest ledger.');
    expect(within(screen.getByTestId('palette-petrol-classic')).getByRole('button', { name: 'Use this palette' })).toBeTruthy();
    // Choosing is all there is: nothing on the screen edits a palette.
    expect(screen.queryByRole('button', { name: /Edit/ })).toBeNull();
    expect(screen.queryByRole('textbox')).toBeNull();
  });

  it('chooses a palette, and goes back to the platform’s design', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/tenant/palettes': { data: { palettes: PALETTES, selected: 'forest-ledger' } },
      'PUT /api/v1/tenant/palette': { data: { palette: PALETTES[2] } },
    });
    renderWith(<PaletteChoiceScreen />, client);

    fireEvent.click(within(await screen.findByTestId('palette-terracotta-studio')).getByRole('button', { name: 'Use this palette' }));
    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT').map((r) => r.body)).toContainEqual({ palette: 'terracotta-studio' }));

    fireEvent.click(screen.getByRole('button', { name: 'Go back to the platform’s design' }));
    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT').map((r) => r.body)).toContainEqual({ palette: null }));
  });

  it('previews a palette before choosing it', async () => {
    renderWith(
      <PaletteChoiceScreen />,
      recordingClient({ 'GET /api/v1/tenant/palettes': { data: { palettes: PALETTES, selected: null } } }).client,
    );

    expect((await screen.findByTestId('palette-worn')).textContent).toBe('Your members see the platform’s own design.');
    fireEvent.click(within(screen.getByTestId('palette-midnight-indigo')).getByRole('button', { name: 'Preview' }));

    const accent = PALETTES[3]?.document.colors.flatMap((group) => group.tokens).find((token) => token.name === 'accent');
    expect(screen.getByTestId('palette-preview-dark').style.getPropertyValue('--color-accent')).toBe(accent?.dark);
    expect(screen.getByText('Preview of Midnight indigo')).toBeTruthy();
  });
});
