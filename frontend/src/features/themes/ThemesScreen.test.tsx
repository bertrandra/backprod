import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, type Stubs } from '@/test-utils';

import { ThemesScreen } from './ThemesScreen';
import type { ThemeDocument } from './themeDocument';

const NAMES = ['petrol-classic', 'forest-ledger', 'terracotta-studio', 'midnight-indigo', 'graphite-compact'];
const TEMPLATES = NAMES.map((name) => ({
  name,
  document: JSON.parse(readFileSync(join(__dirname, '..', '..', '..', '..', 'docs', 'themes', `${name}.json`), 'utf8')) as ThemeDocument,
}));
const AT = '2026-10-04T09:00:00+00:00';
const FOREST = TEMPLATES[1]?.document as ThemeDocument;

function stubs(extra: Stubs = {}): Stubs {
  return {
    'GET /api/v1/tenant/theme-templates': { data: { templates: TEMPLATES } },
    'GET /api/v1/tenant/themes': { data: { themes: [] } },
    ...extra,
  };
}

describe('ThemesScreen', () => {
  it('offers the five templates, each named, described and swatched', async () => {
    renderWith(<ThemesScreen />, recordingClient(stubs()).client);

    for (const { name, document } of TEMPLATES) {
      const card = await screen.findByTestId(`template-${name}`);

      expect(within(card).getByText(document.label ?? '')).toBeTruthy();
      expect(within(card).getByRole('button', { name: 'Edit a copy' })).toBeTruthy();
    }

    expect(screen.getByText('No theme saved yet. Edit a copy of a template to make one.')).toBeTruthy();
  });

  it('edits a copy of a template and saves it under the organisation’s own name', async () => {
    const { client, requests } = recordingClient(
      stubs({
        'PUT /api/v1/tenant/themes/{name}': { data: { theme: { name: 'acme-forest', updated_at: AT, active: false, document: FOREST } } },
      }),
    );
    renderWith(<ThemesScreen />, client);

    fireEvent.click(within(await screen.findByTestId('template-forest-ledger')).getByRole('button', { name: 'Edit a copy' }));
    const editor = screen.getByTestId('theme-editor');

    fireEvent.change(within(editor).getByLabelText('Name'), { target: { value: 'acme-forest' } });
    fireEvent.change(within(editor).getByLabelText('accent — Light (value)'), { target: { value: '#14532d' } });
    fireEvent.click(within(editor).getByTestId('save-tenant-theme'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));
    const put = requests.find((r) => r.method === 'PUT');
    const body = put?.body as { document: ThemeDocument };

    expect(put?.pathParams).toEqual({ name: 'acme-forest' });
    expect(body.document.colors.flatMap((g) => g.tokens).find((t) => t.name === 'accent')?.light).toBe('#14532d');
    // Everything not touched travels as the template had it.
    expect(body.document.fonts).toEqual(FOREST.fonts);
    expect(body.document.type_scale).toEqual(FOREST.type_scale);
  });

  it('says which pair a colour makes unreadable, as it is typed', async () => {
    renderWith(<ThemesScreen />, recordingClient(stubs()).client);

    fireEvent.click(within(await screen.findByTestId('template-petrol-classic')).getByRole('button', { name: 'Edit a copy' }));
    const editor = screen.getByTestId('theme-editor');

    expect(within(editor).getByTestId('theme-contrast').textContent).toBe('Every text pair clears WCAG AA, in both modes.');

    fireEvent.change(within(editor).getByLabelText('muted — Light (value)'), { target: { value: '#c8d0da' } });

    expect(within(editor).getByTestId('theme-contrast').textContent).toContain('Light · muted / canvas');
  });

  it('refuses to send a value that is not CSS', async () => {
    renderWith(<ThemesScreen />, recordingClient(stubs()).client);

    fireEvent.click(within(await screen.findByTestId('template-graphite-compact')).getByRole('button', { name: 'Edit a copy' }));
    const editor = screen.getByTestId('theme-editor');

    fireEvent.change(within(editor).getByLabelText('canvas — Dark (value)'), { target: { value: '#000; }' } });

    expect(within(editor).getByTestId<HTMLButtonElement>('save-tenant-theme').disabled).toBe(true);
  });

  it('chooses the theme the organisation’s screens wear, and can go back to the platform’s', async () => {
    const { client, requests } = recordingClient(
      stubs({
        'GET /api/v1/tenant/themes': {
          data: {
            themes: [
              { name: 'acme-day', updated_at: AT, active: true },
              { name: 'acme-night', updated_at: AT, active: false },
            ],
          },
        },
        'PUT /api/v1/tenant/theme': { data: { theme: null } },
      }),
    );
    renderWith(<ThemesScreen />, client);

    const list = await screen.findByTestId('my-themes');
    expect(within(list).getByText('In use')).toBeTruthy();
    expect(screen.getByText('Your members see acme-day.')).toBeTruthy();

    fireEvent.click(within(list).getByRole('button', { name: 'Use for my organisation' }));
    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT').map((r) => r.body)).toContainEqual({ name: 'acme-night' }));

    fireEvent.click(screen.getByRole('button', { name: 'Go back to the platform’s design' }));
    await waitFor(() => expect(requests.filter((r) => r.method === 'PUT').map((r) => r.body)).toContainEqual({ name: null }));
  });
});

describe('the preview', () => {
  it('sets the draft’s fonts on itself, so a serif template previews in serif', async () => {
    renderWith(<ThemesScreen />, recordingClient(stubs()).client);

    fireEvent.click(within(await screen.findByTestId('template-terracotta-studio')).getByRole('button', { name: 'Edit a copy' }));
    const preview = screen.getByTestId('theme-preview-light');
    const serif = TEMPLATES[2]?.document.fonts.find((font) => font.role === 'sans')?.stack;

    expect(preview.style.getPropertyValue('--font-sans')).toBe(serif);
    expect(preview.style.fontFamily).toBe('var(--font-sans)');
  });
});
