import { currentLocale, isLocale, LOCALE_NAMES, LOCALES, setLocale, t } from './index';

/**
 * The language, chosen before there is anybody to remember it for
 * (2026-09-20, ADR-050): a small select on the storefront and the sign-in
 * form. Applied at once — the tree is keyed on the locale, so the page
 * re-renders in the language just picked — and remembered by the browser;
 * a sign-up then records it on the account, so the choice made as a
 * stranger is the one the person reads in afterwards.
 */
export function LanguageSelect({ className = '' }: { className?: string }) {
  return (
    <select
      aria-label={t("Language")}
      data-testid="language-select"
      value={currentLocale()}
      onChange={(event) => {
        const chosen: unknown = event.target.value;

        if (isLocale(chosen)) {
          void setLocale(chosen);
        }
      }}
      className={`rounded-control border border-line bg-surface px-2 py-1 text-xs text-muted focus-visible:outline-2 focus-visible:outline-offset-2 ${className}`}
    >
      {LOCALES.map((code) => (
        <option key={code} value={code}>
          {LOCALE_NAMES[code]}
        </option>
      ))}
    </select>
  );
}
