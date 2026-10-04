import { waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

import { useTenantTheme } from './useTenantTheme';

const DOCUMENT = {
  format: 1,
  colors: [{ group: 'Accent', tokens: [{ name: 'accent', variable: '--ds-accent', light: '#1d7a4e', dark: '#51d698' }] }],
  fonts: [{ role: 'sans', variable: '--font-sans', family: 'Georgia', stack: 'ui-serif, Georgia, serif' }],
  type_scale: [{ name: 'xl', variable: '--text-xl', size: '1.3rem', line_height: null, letter_spacing: null }],
};

function Painted({ enabled }: { enabled: boolean }) {
  useTenantTheme(enabled);

  return null;
}

function themeRules(): { light: CSSStyleRule | undefined; dark: CSSStyleRule | undefined } {
  const sheet = document.head.querySelector<HTMLStyleElement>('style[data-tenant-theme]')?.sheet;
  const light = sheet?.cssRules[0];
  const media = sheet?.cssRules[1];
  const dark = media instanceof CSSMediaRule ? media.cssRules[0] : undefined;

  return {
    light: light instanceof CSSStyleRule ? light : undefined,
    dark: dark instanceof CSSStyleRule ? dark : undefined,
  };
}

afterEach(() => document.head.querySelectorAll('style[data-tenant-theme]').forEach((element) => element.remove()));

describe('useTenantTheme', () => {
  it('paints :root with the light values, and the dark ones inside the colour-scheme query', async () => {
    renderWith(
      <Painted enabled />,
      stubClient({ 'GET /api/v1/tenant/theme': { data: { theme: { name: 'forest', updated_at: '2026-10-04T09:00:00+00:00', active: true, document: DOCUMENT } } } }),
    );

    await waitFor(() => expect(themeRules().light).toBeDefined());
    const { light, dark } = themeRules();

    expect(light?.selectorText).toBe(':root');
    expect(light?.style.getPropertyValue('--ds-accent')).toBe('#1d7a4e');
    expect(light?.style.getPropertyValue('--font-sans')).toBe('ui-serif, Georgia, serif');
    expect(light?.style.getPropertyValue('--text-xl')).toBe('1.3rem');
    expect(dark?.style.getPropertyValue('--ds-accent')).toBe('#51d698');
  });

  it('paints nothing when the organisation has chosen none', async () => {
    const client = stubClient({ 'GET /api/v1/tenant/theme': { data: { theme: null } } });
    renderWith(<Painted enabled />, client);

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(document.head.querySelector('style[data-tenant-theme]')).toBeNull();
  });

  it('paints nothing on a platform screen, and asks for nothing', async () => {
    renderWith(<Painted enabled={false} />, stubClient({}));

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(document.head.querySelector('style[data-tenant-theme]')).toBeNull();
  });
});
