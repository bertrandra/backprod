import { useEffect, useState } from 'react';

import { currentLocale, detectLocale, onLocaleChange, rememberedLocale, setLocale, type LocaleCode } from './index';

/**
 * The locale as React state: what `App` keys the tree on, so a change
 * re-renders every `t()` — and where the first language is decided, once,
 * from the address, the browser's memory and the browser's own language.
 */
export function useLocale(): { locale: LocaleCode; ready: boolean; change: (locale: LocaleCode) => Promise<void> } {
  const [locale, setState] = useState<LocaleCode>(currentLocale);
  // English needs no catalogue, so a page that will read in English paints at
  // once; any other language waits for its chunk rather than flashing English.
  const [ready, setReady] = useState(() => detectLocale(window.location.search, rememberedLocale(), navigator.languages).locale === currentLocale());

  useEffect(() => {
    const stop = onLocaleChange((next) => {
      setState(next);
      setReady(true);
    });

    const { locale: first } = detectLocale(window.location.search, rememberedLocale(), navigator.languages);

    void setLocale(first).then(() => setReady(true));

    return stop;
  }, []);

  return { locale, ready, change: setLocale };
}

/** Whether the address named a language, which then outranks the person's own. */
export function localeNamedInAddress(): boolean {
  return detectLocale(window.location.search, null, []).explicit;
}
