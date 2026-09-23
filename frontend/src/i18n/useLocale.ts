import { useEffect, useState } from 'react';

import { currentLocale, detectLocale, onLocaleChange, rememberedLocale, setLocale, type LocaleCode } from './index';

/**
 * The locale as React state: what `App` keys the tree on, so a change
 * re-renders every `t()` — and where the first language is decided, once,
 * from the browser's memory and the browser's own language.
 *
 * Only the first. Once `/me` answers, the person's own choice applies over
 * whatever was decided here (`AppShell`), and nothing in the address may
 * override it any more — `?lang=` was removed on 2026-09-23.
 */
export function useLocale(): { locale: LocaleCode; ready: boolean; change: (locale: LocaleCode) => Promise<void> } {
  const [locale, setState] = useState<LocaleCode>(currentLocale);
  // English needs no catalogue, so a page that will read in English paints at
  // once; any other language waits for its chunk rather than flashing English.
  const [ready, setReady] = useState(() => detectLocale(rememberedLocale(), navigator.languages) === currentLocale());

  useEffect(() => {
    const stop = onLocaleChange((next) => {
      setState(next);
      setReady(true);
    });

    void setLocale(detectLocale(rememberedLocale(), navigator.languages)).then(() => setReady(true));

    return stop;
  }, []);

  return { locale, ready, change: setLocale };
}
