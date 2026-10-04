import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PALETTES } from './fixtures';
import { PaletteEditor, tabFromHash } from './PaletteEditor';
import { themeCss, type ThemeDocument } from './themeDocument';

const PETROL = PALETTES[0]?.document as ThemeDocument;

function editor(onSave = vi.fn(), origin = {}) {
  render(
    <PaletteEditor
      initial={{ name: 'petrol-classic', document: PETROL }}
      palettes={PALETTES}
      origin={origin}
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

/**
 * The colour list (2026-10-05), after Plan's palette screen: a role on every
 * token, filters, a search, and each row's state in words.
 */
describe('PaletteEditor — the colour list', () => {
  const row = (name: string) => document.querySelector(`[data-token="${name}"]`);

  it('says what each colour paints', () => {
    editor();

    expect(within(row('well') as HTMLElement).getByText(/A recess inside a card/)).toBeTruthy();
  });

  it('searches by name or by what a colour paints', () => {
    editor();
    fireEvent.change(screen.getByLabelText('Search a colour or what it paints'), { target: { value: 'dims the page' } });

    expect(row('scrim')).toBeTruthy();
    expect(row('accent')).toBeNull();

    fireEvent.change(screen.getByLabelText('Search a colour or what it paints'), { target: { value: 'nothing-like-this' } });
    expect(screen.getByTestId('colour-list-empty').textContent).toContain('nothing-like-this');
  });

  it('marks a colour not saved, and keeps it under Changed', () => {
    editor();
    fireEvent.click(screen.getByTestId('colour-filter-changed'));
    expect(screen.getByTestId('colour-list-empty')).toBeTruthy();

    fireEvent.click(screen.getByTestId('colour-filter-all'));
    // Dark enough to keep every pair it is in readable, so the row's state is
    // the edit and not a contrast failure.
    fireEvent.change(screen.getByLabelText('accent — Light (value)'), { target: { value: '#0a4a66' } });
    expect(screen.getByTestId('token-state-accent').textContent).toBe('Not saved');

    fireEvent.click(screen.getByTestId('colour-filter-changed'));
    expect(screen.getByTestId('colour-filter-changed').textContent).toContain('1');
    expect(row('accent')).toBeTruthy();
    expect(row('ink')).toBeNull();
  });

  it('says a colour has moved away from the design system', () => {
    editor(vi.fn(), { accent: { light: '#000001', dark: colour(PETROL, 'accent', 'dark') } });

    expect(screen.getByTestId('token-state-accent').textContent).toBe('Changed from the design system');
  });

  it('puts a failing pair first, and filters to it', () => {
    editor();
    fireEvent.change(screen.getByLabelText('ink — Light (value)'), { target: { value: colour(PETROL, 'canvas', 'light') } });

    expect(screen.getByTestId('token-state-ink').textContent).toMatch(/^Contrast \d\.\d\d:1$/);

    fireEvent.click(screen.getByTestId('colour-filter-contrast'));
    expect(row('ink')).toBeTruthy();
    expect(row('canvas')).toBeTruthy();
    expect(row('danger')).toBeNull();
  });
});

/**
 * After Plan's palette screen (2026-10-05): the open tab lives in the
 * address, and the contrast warning leads to the failing pairs.
 */
describe('PaletteEditor — the tab in the address, and the way to the failing pairs', () => {
  afterEach(() => window.history.replaceState(null, '', '/console/palettes'));

  it('reads a tab from the address, and anything else is the first', () => {
    expect(tabFromHash('#contrast')).toBe('contrast');
    expect(tabFromHash('#css')).toBe('css');
    expect(tabFromHash('')).toBe('palette');
    expect(tabFromHash('#nowhere')).toBe('palette');
  });

  it('opens on the tab the address names, and writes the tab chosen', () => {
    window.history.replaceState(null, '', '/console/palettes#fonts');
    editor();

    expect(screen.getByRole('tab', { name: 'Fonts' }).getAttribute('aria-selected')).toBe('true');

    fireEvent.click(screen.getByRole('tab', { name: 'CSS' }));
    expect(window.location.hash).toBe('#css');
    expect(window.location.pathname).toBe('/console/palettes');

    // The first tab is the address without a hash.
    fireEvent.click(screen.getByRole('tab', { name: 'Palette' }));
    expect(window.location.hash).toBe('');
  });

  it('leads from the warning to the Contrast tab, narrowed to the pairs that fail', () => {
    editor();
    fireEvent.change(screen.getByLabelText('ink — Light (value)'), { target: { value: colour(PETROL, 'canvas', 'light') } });
    fireEvent.click(screen.getByTestId('show-failing-pairs'));

    expect(screen.getByRole('tab', { name: 'Contrast' }).getAttribute('aria-selected')).toBe('true');
    expect(screen.getByTestId<HTMLInputElement>('only-failing-pairs').checked).toBe(true);
    expect(document.querySelector('[data-pair="ink/canvas"]')).toBeTruthy();
    expect(document.querySelector('[data-pair="danger/danger-wash"]')).toBeNull();

    // Unticked, every pair is back.
    fireEvent.click(screen.getByTestId('only-failing-pairs'));
    expect(document.querySelector('[data-pair="danger/danger-wash"]')).toBeTruthy();
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
