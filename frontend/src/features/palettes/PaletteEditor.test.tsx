import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PALETTES } from './fixtures';
import { PaletteEditor } from './PaletteEditor';
import { themeCss, type ThemeDocument } from './themeDocument';

const PETROL = PALETTES[0]?.document as ThemeDocument;

function editor(onSave = vi.fn()) {
  render(
    <PaletteEditor
      initial={{ name: 'petrol-classic', document: PETROL }}
      palettes={PALETTES}
      saving={false}
      saved={false}
      error={null}
      onSave={onSave}
      onClose={() => undefined}
    />,
  );

  return onSave;
}

function savedDocument(onSave: ReturnType<typeof vi.fn>): ThemeDocument {
  fireEvent.click(screen.getByTestId('save-palette'));

  return onSave.mock.calls.at(-1)?.[1] as ThemeDocument;
}

const colour = (document: ThemeDocument, name: string, mode: 'light' | 'dark') =>
  document.colors.flatMap((group) => group.tokens).find((token) => token.name === name)?.[mode];

describe('PaletteEditor — the tabs', () => {
  it('has four tabs, moved between with the arrow keys, and the preview beside every one', () => {
    editor();
    const tabs = within(screen.getByRole('tablist', { name: 'Palette design' })).getAllByRole('tab');

    expect(tabs.map((tab) => tab.textContent)).toEqual(['Palette', 'Fonts', 'CSS', 'Contrast']);
    expect(tabs[0]?.getAttribute('aria-selected')).toBe('true');
    expect(tabs[1]?.tabIndex).toBe(-1);

    fireEvent.keyDown(tabs[0] as HTMLElement, { key: 'ArrowRight' });
    expect(screen.getByRole('tab', { name: 'Fonts' }).getAttribute('aria-selected')).toBe('true');
    expect(screen.getByTestId('palette-fonts')).toBeTruthy();
    expect(screen.getByTestId('palette-preview')).toBeTruthy();

    fireEvent.keyDown(screen.getByRole('tab', { name: 'Fonts' }), { key: 'End' });
    expect(screen.getByTestId('palette-contrast-tab')).toBeTruthy();
    expect(screen.getByTestId('palette-preview')).toBeTruthy();

    fireEvent.keyDown(screen.getByRole('tab', { name: 'Contrast' }), { key: 'ArrowRight' });
    expect(screen.getByRole('tab', { name: 'Palette' }).getAttribute('aria-selected')).toBe('true');
  });

  it('previews one mode at a time, chosen beside the preview', () => {
    editor();

    expect(screen.getByTestId('palette-preview-light')).toBeTruthy();
    fireEvent.click(within(screen.getByRole('group', { name: 'Mode' })).getByRole('button', { name: 'Dark' }));
    expect(screen.queryByTestId('palette-preview-light')).toBeNull();
    expect(screen.getByTestId('palette-preview-dark').style.getPropertyValue('--color-canvas')).toBe(colour(PETROL, 'canvas', 'dark'));
  });
});

describe('PaletteEditor — choosing a colour', () => {
  it('opens a colour’s panel: picker, code, RGB, HSL, shades, the palette’s own colours and their contrast', () => {
    editor();
    fireEvent.click(screen.getByRole('button', { name: 'Choose accent — Light' }));
    const panel = screen.getByTestId('colour-panel');

    expect(within(panel).getByLabelText<HTMLInputElement>('accent — Light').type).toBe('color');
    expect(within(panel).getByLabelText<HTMLInputElement>('accent — Light — R').value).toBe('11');
    expect(within(panel).getByLabelText('accent — Light — Hue')).toBeTruthy();
    expect(within(panel).getByText('Shades of this hue')).toBeTruthy();
    expect(within(panel).getByText('Already in this palette')).toBeTruthy();
    // accent sits on surface, and on-accent sits on it: both pairs measured.
    expect(within(panel).getByText('surface')).toBeTruthy();
    expect(within(panel).getByText('on-accent')).toBeTruthy();
  });

  it('writes what each control chooses into the draft', () => {
    const onSave = editor();
    fireEvent.click(screen.getByRole('button', { name: 'Choose accent — Light' }));
    const panel = screen.getByTestId('colour-panel');

    fireEvent.change(within(panel).getByLabelText('accent — Light — R'), { target: { value: '255' } });
    expect(colour(savedDocument(onSave), 'accent', 'light')).toBe('#ff6e99');

    fireEvent.change(within(panel).getByLabelText('accent — Light'), { target: { value: '#123456' } });
    expect(colour(savedDocument(onSave), 'accent', 'light')).toBe('#123456');

    const ink = colour(PETROL, 'ink', 'light') ?? '';
    fireEvent.click(within(panel).getByRole('button', { name: `Use ${ink}` }));
    expect(colour(savedDocument(onSave), 'accent', 'light')).toBe(ink);

    fireEvent.change(screen.getByLabelText('accent — Light (value)'), { target: { value: '#abcdef' } });
    expect(colour(savedDocument(onSave), 'accent', 'light')).toBe('#abcdef');
  });

  it('edits a value with transparency as text, and says why', () => {
    editor();
    fireEvent.click(screen.getByRole('button', { name: 'Choose scrim — Light' }));

    expect(within(screen.getByTestId('colour-panel')).getByText(/edited as text/)).toBeTruthy();
    expect(within(screen.getByTestId('colour-panel')).queryByText('Shades of this hue')).toBeNull();
  });
});

describe('PaletteEditor — fonts, CSS and contrast', () => {
  it('changes a family and a step of the scale', () => {
    const onSave = editor();
    fireEvent.click(screen.getByRole('tab', { name: 'Fonts' }));

    fireEvent.change(screen.getByLabelText('font-sans'), { target: { value: "ui-serif, Georgia, Cambria, 'Times New Roman', serif" } });
    fireEvent.change(screen.getByLabelText('text-xl — Size'), { target: { value: '1.4rem' } });
    fireEvent.change(screen.getByLabelText('text-xl — Letter spacing'), { target: { value: '' } });

    const saved = savedDocument(onSave);
    expect(saved.fonts.find((font) => font.role === 'sans')?.family).toBe('Georgia');
    expect(saved.type_scale.find((step) => step.name === 'xl')).toMatchObject({ size: '1.4rem', letter_spacing: null });
  });

  it('shows the stylesheet the draft amounts to, and its JSON', () => {
    editor();
    fireEvent.click(screen.getByRole('tab', { name: 'CSS' }));

    const css = screen.getByTestId('palette-css-text').textContent ?? '';
    expect(css).toBe(themeCss(PETROL));
    expect(css).toContain(`--ds-accent: ${colour(PETROL, 'accent', 'light') ?? ''};`);
    expect(css).toContain('@media (prefers-color-scheme: dark)');
    expect(JSON.parse(screen.getByTestId('palette-json-text').textContent ?? '')).toEqual(PETROL);
  });

  it('measures every pair in both modes, and any two colours chosen', () => {
    editor();
    fireEvent.click(screen.getByRole('tab', { name: 'Contrast' }));

    const row = document.querySelector('[data-pair="ink/canvas"]') as HTMLElement;
    expect(within(row).getAllByText(/^\d+\.\d\d:1$/)).toHaveLength(2);

    fireEvent.change(screen.getByLabelText('Text'), { target: { value: 'canvas' } });
    fireEvent.change(screen.getByLabelText('Background'), { target: { value: 'canvas' } });
    expect(within(screen.getByTestId('contrast-check')).getAllByText('1.00:1')).toHaveLength(2);
    expect(within(screen.getByTestId('contrast-check')).getAllByText('Fails')).toHaveLength(2);
  });
});
