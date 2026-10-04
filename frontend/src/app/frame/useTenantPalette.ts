import { useEffect } from 'react';

import { themeProperties, type ThemeDocument } from '@/features/palettes/themeDocument';
import { useWornPalette } from '@/queries/palettes';

/**
 * Paints the page in the palette the organisation wears (2026-10-04).
 *
 * One `<style>` element after the stylesheet, holding two rules: `:root` with
 * the light values, the fonts and the type scale, and the same `:root` inside
 * `prefers-color-scheme: dark` with the dark values. Same selectors as
 * `index.css`, later in the document, so they win — and unlayered, so they also
 * win over Tailwind's `@layer theme`.
 *
 * **Set through the CSSOM, never written as text.** `style.setProperty` takes
 * a name and a value and cannot be made to close a rule, whatever the value
 * holds; the server refuses such a value already, and {@see themeProperties}
 * refuses it again. Three locks on one door because the door is every
 * member's screen.
 *
 * **Not on the console.** A platform screen answers to the platform, and wears
 * its design: an organisation's palette stops at the organisation's screens.
 */
export function useTenantPalette(enabled: boolean): void {
  const worn = useWornPalette(enabled);
  const document = enabled ? (worn.data?.document ?? null) : null;

  useEffect(() => applyTheme(document), [document]);
}

const MARKER = 'data-tenant-theme';

function applyTheme(theme: ThemeDocument | null): () => void {
  if (theme === null) {
    return () => undefined;
  }

  const element = document.createElement('style');
  element.setAttribute(MARKER, '');
  document.head.append(element);

  const sheet = element.sheet;

  if (sheet !== null) {
    sheet.insertRule(':root {}', 0);
    sheet.insertRule('@media (prefers-color-scheme: dark) { :root {} }', 1);

    const light = sheet.cssRules[0];
    const media = sheet.cssRules[1];
    const dark = media instanceof CSSMediaRule ? media.cssRules[0] : undefined;

    set(light, themeProperties(theme, 'light'));
    set(dark, themeProperties(theme, 'dark'));
  }

  return () => element.remove();
}

function set(rule: CSSRule | undefined, properties: Record<string, string>): void {
  if (!(rule instanceof CSSStyleRule)) {
    return;
  }

  for (const [name, value] of Object.entries(properties)) {
    rule.style.setProperty(name, value);
  }
}
