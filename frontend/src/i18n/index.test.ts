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

describe('two languages asked for at once', () => {
  it('applies the one asked for last, not the one whose catalogue arrives last', async () => {
    // German is not installed in this file, so its catalogue is a real
    // `import()` and this call has to wait for it. English needs none and
    // finishes first. Without a sequence, German would land afterwards and
    // overwrite a choice made after it — which is what made one profile
    // test fail for days under the name "flaky".
    const german = setLocale('de');
    const english = setLocale('en');

    await Promise.all([german, english]);

    expect(currentLocale()).toBe('en');
  });

  it('keeps the catalogue it loaded, so the next ask for it costs nothing', async () => {
    const italian = setLocale('it');
    await Promise.all([italian, setLocale('en')]);

    installCatalogue('it', { 'Rank of {name}': 'Rango di {name}' });
    await setLocale('it');

    expect(t('Rank of {name}', { name: 'Pro' })).toBe('Rango di Pro');
  });
});

describe('detectLocale', () => {
  it('is what the browser remembered, first', () => {
    expect(detectLocale('it', ['es-ES'])).toBe('it');
  });

  it('then the browser’s own languages, by their two-letter code, skipping ones the platform does not speak', () => {
    expect(detectLocale(null, ['pt-BR', 'es-419'])).toBe('es');
  });

  it('and English when nothing applies', () => {
    expect(detectLocale(null, ['ja'])).toBe('en');
  });

  it('ignores a remembered value the platform does not speak', () => {
    // Whatever wrote it — an older version, a hand-edited storage, a
    // language since dropped — it is not one of ours, so it decides
    // nothing and the browser's own answer applies.
    expect(detectLocale('pt', ['fr-FR'])).toBe('fr');
  });
});
