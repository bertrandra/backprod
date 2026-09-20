/**
 * The language a person reads in (2026-09-19, ADR-050).
 *
 * **English is the key.** `t('Buy for yourself')` is the English sentence
 * itself, so the source reads as the screen does, a missing translation
 * falls back to English rather than to `billing.buy.self`, and English costs
 * nothing to load. The other languages are catalogues — `{ 'English': 'Traduction' }`
 * — imported on demand, one chunk each, cached by the browser.
 *
 * **Presentation, never data.** What varies by language is the words on the
 * screen and in a mail; codes, enumerations, offer names and error codes stay
 * what the API says. Amounts and dates were already the browser's `Intl`'s;
 * they now take this locale rather than the browser's.
 *
 * **One module-level catalogue, no context.** `t` is a plain function so it
 * works in a hook, a component, a query and a plain object alike, and a
 * language change remounts the tree (`useLocale` keys it), which is rare
 * enough that remounting is the honest cost — cheaper than 800 subscriptions.
 */
export const LOCALES = ['en', 'fr', 'es', 'de', 'it'] as const;
export type LocaleCode = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: LocaleCode = 'en';

export const LOCALE_NAMES: Record<LocaleCode, string> = {
  en: 'English',
  fr: 'Français',
  es: 'Español',
  de: 'Deutsch',
  it: 'Italiano',
};

type Catalogue = Readonly<Record<string, string>>;

let current: LocaleCode = DEFAULT_LOCALE;
let catalogue: Catalogue = {};
const loaded = new Map<LocaleCode, Catalogue>();
const listeners = new Set<(locale: LocaleCode) => void>();

const STORAGE_KEY = 'backprod.locale';

export function isLocale(value: unknown): value is LocaleCode {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value);
}

/** The locale in force. */
export function currentLocale(): LocaleCode {
  return current;
}

/**
 * Translates an English sentence, filling `{name}` placeholders.
 *
 * A key with no entry — or an English locale — is returned as written, with
 * its placeholders filled: English is never "missing".
 */
export function t(english: string, vars?: Readonly<Record<string, string | number>>): string {
  const text = catalogue[english] ?? english;

  if (vars === undefined) {
    return text;
  }

  return text.replace(/\{([a-zA-Z_]+)\}/g, (match, name: string) => {
    const value = vars[name];

    return value === undefined ? match : String(value);
  });
}

/**
 * Loads and applies a language. English needs no catalogue; the others are
 * one `import()` each, which Vite splits into its own chunk.
 */
export async function setLocale(locale: LocaleCode): Promise<void> {
  let applied = locale;

  if (locale !== DEFAULT_LOCALE && !loaded.has(locale)) {
    try {
      const module: unknown = await import(`./catalogues/${locale}.json`);
      loaded.set(locale, (module as { default: Catalogue }).default);
    } catch {
      // The chunk did not arrive — a flaky connection, a deployment mid-way.
      // English on the screen is a page; a page that never renders is not.
      applied = DEFAULT_LOCALE;
    }
  }

  current = applied;
  catalogue = applied === DEFAULT_LOCALE ? {} : (loaded.get(applied) ?? {});

  try {
    window.localStorage.setItem(STORAGE_KEY, applied);
  } catch {
    // Storage may be blocked; the choice then lasts the page.
  }

  for (const listener of listeners) {
    listener(applied);
  }
}

/**
 * The language to start in: `?lang=` in the address, else what the browser
 * remembered, else the browser's own, else English. Signing in may then
 * apply the person's own choice (`/me.locale`), which the address still
 * outranks — a link is somebody saying which language they mean now.
 */
export function detectLocale(search: string, remembered: string | null, navigatorLanguages: readonly string[]): { locale: LocaleCode; explicit: boolean } {
  const asked = new URLSearchParams(search).get('lang');

  if (isLocale(asked)) {
    return { locale: asked, explicit: true };
  }

  if (isLocale(remembered)) {
    return { locale: remembered, explicit: false };
  }

  for (const language of navigatorLanguages) {
    const short = language.slice(0, 2).toLowerCase();

    if (isLocale(short)) {
      return { locale: short, explicit: false };
    }
  }

  return { locale: DEFAULT_LOCALE, explicit: false };
}

export function rememberedLocale(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

export function onLocaleChange(listener: (locale: LocaleCode) => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

/** For tests: the catalogue without a network. */
export function installCatalogue(locale: LocaleCode, entries: Catalogue): void {
  loaded.set(locale, entries);
}
