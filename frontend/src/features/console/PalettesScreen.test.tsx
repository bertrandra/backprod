import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AT, PALETTES } from '@/features/palettes/fixtures';
import type { ThemeDocument } from '@/features/palettes/themeDocument';
import { recordingClient, renderWith, type Stubs } from '@/test-utils';

import { PalettesScreen } from './PalettesScreen';

const ATLAS = '33333333-3333-4333-8333-333333333333';
const BETA = '44444444-4444-4444-8444-444444444444';
const ACME = '55555555-5555-4555-8555-555555555555';
const BASIC = '66666666-6666-4666-8666-666666666666';

const MATRIX = {
  products: [
    { id: ATLAS, code: 'atlas', name: 'Atlas' },
    { id: BETA, code: 'beta', name: 'Beta' },
  ],
  tenants: [
    { id: ACME, name: 'Acme', slug: 'acme', products: [{ product_id: ATLAS, palette: 'forest-ledger' }, { product_id: BETA, palette: null }] },
    { id: BASIC, name: 'Basic', slug: 'basic', products: [{ product_id: ATLAS, palette: 'terracotta-studio' }] },
  ],
  total: 2,
  limit: 50,
  offset: 0,
};

function stubs(extra: Stubs = {}): Stubs {
  return {
    'GET /api/v1/staff/palettes': { data: { palettes: PALETTES } },
    'GET /api/v1/staff/palette-assignments': { data: MATRIX },
    ...extra,
  };
}

describe('PalettesScreen — the matrix', () => {
  it('shows organisations by product, with a cell only where the product is held', async () => {
    renderWith(<PalettesScreen />, recordingClient(stubs()).client, { product: null });

    const matrix = await screen.findByTestId('palette-matrix');
    const acme = within(matrix).getByRole('row', { name: /Acme/ });
    const basic = within(matrix).getByRole('row', { name: /Basic/ });

    expect(within(acme).getByLabelText<HTMLSelectElement>('Acme in Atlas').value).toBe('forest-ledger');
    expect(within(acme).getByLabelText<HTMLSelectElement>('Acme in Beta').value).toBe('');
    expect(within(basic).getByLabelText<HTMLSelectElement>('Basic in Atlas').value).toBe('terracotta-studio');
    // Basic does not hold Beta: no choice, a dash.
    expect(within(basic).queryByLabelText('Basic in Beta')).toBeNull();
    expect(within(basic).getByLabelText('Not held').textContent).toBe('—');
  });

  it('assigns a palette from a cell, and gives the platform’s design back', async () => {
    const { client, requests } = recordingClient(stubs({ 'PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/palette': { data: { palette: null } } }));
    renderWith(<PalettesScreen />, client, { product: null });

    fireEvent.change(await screen.findByLabelText('Acme in Beta'), { target: { value: 'midnight-indigo' } });
    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const put = requests.find((r) => r.method === 'PUT');
    expect(put?.pathParams).toEqual({ tenantId: ACME, productId: BETA });
    expect(put?.body).toEqual({ palette: 'midnight-indigo' });

    fireEvent.change(screen.getByLabelText('Acme in Atlas'), { target: { value: '' } });
    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT').map((r) => r.body)).toContainEqual({ palette: null }));
  });
});

describe('PalettesScreen — the palettes', () => {
  it('edits a palette in place, under its own name', async () => {
    const forest = PALETTES[1];
    const { client, requests } = recordingClient(
      stubs({ 'PUT /api/v1/staff/palettes/{name}': { data: { palette: { name: 'forest-ledger', updated_at: AT, document: forest?.document } } } }),
    );
    renderWith(<PalettesScreen />, client, { product: null });

    fireEvent.click(within(await screen.findByTestId('palette-forest-ledger')).getByRole('button', { name: 'Edit' }));
    const editor = screen.getByTestId('palette-editor');

    fireEvent.change(within(editor).getByLabelText('accent — Light (value)'), { target: { value: '#14532d' } });
    fireEvent.click(within(editor).getByTestId('save-palette'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));
    const put = requests.find((r) => r.method === 'PUT');
    const body = put?.body as { document: ThemeDocument };

    expect(put?.pathParams).toEqual({ name: 'forest-ledger' });
    expect(body.document.colors.flatMap((g) => g.tokens).find((tk) => tk.name === 'accent')?.light).toBe('#14532d');
    expect(body.document.fonts).toEqual(forest?.document.fonts);
    await waitFor(() => expect(within(editor).getByTestId('palette-saved')).toBeTruthy());
  });

  it('starts a new palette from one, under a name nobody has', async () => {
    renderWith(<PalettesScreen />, recordingClient(stubs()).client, { product: null });

    fireEvent.click(within(await screen.findByTestId('palette-graphite-compact')).getByRole('button', { name: 'Start a new one from it' }));

    expect(within(screen.getByTestId('palette-editor')).getByLabelText<HTMLInputElement>('Name').value).toBe('graphite-compact-copy');
  });

  it('measures every pair as a colour changes, and refuses a value that is not CSS', async () => {
    renderWith(<PalettesScreen />, recordingClient(stubs()).client, { product: null });

    fireEvent.click(within(await screen.findByTestId('palette-petrol-classic')).getByRole('button', { name: 'Edit' }));
    const editor = screen.getByTestId('palette-editor');

    expect(within(editor).getByTestId('palette-contrast').textContent).toBe('Every text pair clears WCAG AA, in both modes.');
    fireEvent.change(within(editor).getByLabelText('muted — Light (value)'), { target: { value: '#c8d0da' } });
    expect(within(editor).getByTestId('palette-contrast').textContent).toContain('Light · muted / canvas');

    fireEvent.change(within(editor).getByLabelText('canvas — Dark (value)'), { target: { value: '#000; }' } });
    expect(within(editor).getByTestId<HTMLButtonElement>('save-palette').disabled).toBe(true);
  });
});
