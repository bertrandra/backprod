import { afterEach, describe, expect, it } from 'vitest';

import { currentLocale, detectLocale, installCatalogue, setLocale, t } from './index';

afterEach(async () => {
  await setLocale('en');
});

describe('t', () => {
  it('returns the English sentence itself in English, placeholders filled', () => {
    expect(t('Rank of {name}', { name: 'Pro' })).toBe('Rank of Pro');
  });

  it('translates from the catalogue once a language is applied, keeping the placeholders', async () => {
    installCatalogue('fr', { 'Rank of {name}': 'Rang de {name}' });
    await setLocale('fr');

    expect(currentLocale()).toBe('fr');
    expect(t('Rank of {name}', { name: 'Pro' })).toBe('Rang de Pro');
  });

  it('falls back to English for a sentence the catalogue does not have', async () => {
    installCatalogue('fr', {});
    await setLocale('fr');

    expect(t('Buy for yourself')).toBe('Buy for yourself');
  });

  it('leaves an unknown placeholder as written rather than emptying it', () => {
    expect(t('Until {value}', {})).toBe('Until {value}');
  });
});

describe('detectLocale', () => {
  it('is the address first, and says so', () => {
    expect(detectLocale('?lang=de', 'fr', ['es-ES'])).toEqual({ locale: 'de', explicit: true });
  });

  it('then what the browser remembered', () => {
    expect(detectLocale('', 'it', ['es-ES'])).toEqual({ locale: 'it', explicit: false });
  });

  it('then the browser’s own languages, by their two-letter code, skipping ones the platform does not speak', () => {
    expect(detectLocale('', null, ['pt-BR', 'es-419'])).toEqual({ locale: 'es', explicit: false });
  });

  it('and English when nothing applies, ignoring a language the platform does not speak in the address', () => {
    expect(detectLocale('?lang=pt', null, ['ja'])).toEqual({ locale: 'en', explicit: false });
  });
});
