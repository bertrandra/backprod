/**
 * The vocabularies structured fields pick from — countries and currencies —
 * and their names in the reader's language.
 *
 * **Codes are shipped, names are asked of `Intl`.** The API speaks ISO 3166-1
 * alpha-2 for a country and ISO 4217 for a currency, and never a name: a name
 * is presentation, and the same rule `Money.tsx` follows for the exponent
 * holds here — the browser carries a table per locale, so a table copied
 * into this bundle would be a second one that drifts. What `Intl` cannot do
 * is *enumerate*: `Intl.DisplayNames` names a region when asked but lists
 * none, so the country list is the one thing written down, and it is the
 * ISO list itself (249 codes), nothing invented. Currencies it can list
 * (`Intl.supportedValuesOf`); the fallback below is for the few browsers
 * that predate that, and is deliberately the short list a person is likely
 * to want rather than a partial copy of the standard.
 *
 * Why a list at all, rather than the two-letter input this replaces: a
 * country code is what the VAT rate is looked up by, and `GBR`, `UK` and
 * `Fr` were each refused by a validator after the person had typed them. A
 * picker cannot be typed wrong, is read back as a name rather than a code,
 * and still sends the code — the field's value never stops being ISO.
 */

export const COUNTRY_CODES: readonly string[] = (
  'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ ' +
  'BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ ' +
  'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ ' +
  'DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR ' +
  'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY ' +
  'HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP ' +
  'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY ' +
  'MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ ' +
  'NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY ' +
  'QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ ' +
  'TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ ' +
  'VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'
).split(' ');

const FALLBACK_CURRENCIES: readonly string[] = (
  'AUD BRL CAD CHF CNY CZK DKK EUR GBP HKD HUF ILS INR JPY KRW MAD MXN NOK NZD PLN RON SEK SGD TND TRY USD ZAR'
).split(' ');

export type Option = { readonly code: string; readonly name: string };

function displayNames(type: 'region' | 'currency', locale?: string): Intl.DisplayNames | null {
  try {
    return new Intl.DisplayNames(locale === undefined ? undefined : [locale], { type, fallback: 'code' });
  } catch {
    return null;
  }
}

/**
 * A country's name for the reader, or the code itself where the browser
 * has no name — never a blank, so a saved profile always reads back.
 */
export function countryName(code: string, locale?: string): string {
  return displayNames('region', locale)?.of(code) ?? code;
}

export function currencyName(code: string, locale?: string): string {
  return displayNames('currency', locale)?.of(code) ?? code;
}

function sortedByName(codes: readonly string[], name: (code: string) => string, locale?: string): Option[] {
  const collator = new Intl.Collator(locale);

  return codes
    .map((code) => ({ code, name: name(code) }))
    .sort((a, b) => collator.compare(a.name, b.name));
}

/** Every country, named and in the reader's alphabetical order. */
export function countryOptions(locale?: string): Option[] {
  return sortedByName(COUNTRY_CODES, (code) => countryName(code, locale), locale);
}

/**
 * The currencies the browser knows, named. `Intl.supportedValuesOf` is the
 * standard's own list; without it, the short list of the ones a person is
 * likely to price in.
 */
export function currencyCodes(): readonly string[] {
  try {
    const supported = (Intl as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('currency');

    return supported === undefined || supported.length === 0 ? FALLBACK_CURRENCIES : supported;
  } catch {
    return FALLBACK_CURRENCIES;
  }
}

/**
 * Every currency, named, with the ones a value already holds kept even when
 * the browser's list lacks them: a saved `TND` must stay selectable on a
 * browser that only lists the fallback.
 */
export function currencyOptions(keep: readonly string[] = [], locale?: string): Option[] {
  const codes = new Set(currencyCodes());

  for (const code of keep) {
    if (/^[A-Z]{3}$/.test(code)) {
      codes.add(code);
    }
  }

  return sortedByName([...codes], (code) => currencyName(code, locale), locale);
}
