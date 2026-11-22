import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith } from '@/test-utils';

import { ProductDemonstrations } from './ProductDemonstrations';

const FOUND = {
  source: 'admin/vitrine/{n}',
  embed: '?mode=demo&file={n}&x={width}&y={height}',
  demonstrations: [
    { number: 1, name: 'The plot', embed_url: 'https://plan.example.test/?mode=demo&file=1&x={width}&y={height}' },
    { number: 4, name: 'The plot, with the pool', embed_url: 'https://plan.example.test/?mode=demo&file=4&x={width}&y={height}' },
  ],
};

/**
 * Where a product keeps its demonstrations (2026-11-22): two paths the
 * platform is told, and what they find.
 */
describe('a product’s demonstrations, on the console', () => {
  it('shows what answers under the two paths the product was given', async () => {
    const { client } = recordingClient({ 'GET /api/v1/staff/configuration/demonstrations': { data: FOUND } });

    renderWith(<ProductDemonstrations productCode="plan" />, client);

    await waitFor(() => expect(screen.getByTestId('demonstrations-found-plan')).toBeTruthy());

    expect(screen.getByDisplayValue('admin/vitrine/{n}')).toBeTruthy();
    expect(
      [...screen.getByTestId('demonstrations-found-plan').querySelectorAll('[data-demonstration]')].map((item) => item.textContent),
    ).toEqual(['1The plot', '4The plot, with the pool']);
  });

  it('lists nothing, and says nothing found nothing, for a product never told where to look', async () => {
    const { client } = recordingClient({
      'GET /api/v1/staff/configuration/demonstrations': { data: { source: '', embed: '', demonstrations: [] } },
    });

    renderWith(<ProductDemonstrations productCode="plan" />, client);

    await waitFor(() => expect(screen.getByTestId('demonstrations-form-plan')).toBeTruthy());

    // "Nothing answered" is about two paths that were tried; here none was.
    expect(screen.queryByTestId('demonstrations-found-plan')).toBeNull();
  });

  it('sends both paths as typed, and names the product it is about', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/configuration/demonstrations': { data: { source: '', embed: '', demonstrations: [] } },
      'PUT /api/v1/staff/configuration/demonstrations': { data: { ...FOUND, demonstrations: [] } },
    });

    renderWith(<ProductDemonstrations productCode="plan" />, client);

    await waitFor(() => expect(screen.getByTestId('demonstrations-form-plan')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Where one is asked for'), { target: { value: 'admin/vitrine/{n}' } });
    fireEvent.change(screen.getByLabelText('What a page embeds to show it'), { target: { value: '?mode=demo&file={n}' } });
    fireEvent.click(screen.getByTestId('save-demonstrations-plan'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));

    const sent = requests.find((r) => r.method === 'PUT');

    expect(sent?.body).toEqual({ source: 'admin/vitrine/{n}', embed: '?mode=demo&file={n}' });
    expect(sent?.query).toEqual({ product: 'plan' });
  });
});
