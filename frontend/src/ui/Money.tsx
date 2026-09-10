import type { Schemas } from '@/api/client';

/**
 * Money on screen.
 *
 * The API speaks integer minor units and an ISO 4217 code, and never a float —
 * a cent lost to binary rounding is a cent an auditor asks about. So this
 * converts for **display only**, at the last possible moment, and nothing in the
 * application ever holds a decimal amount in a variable.
 *
 * **Two minor units is an assumption, not a fact.** Dividing by 100 is right for
 * EUR and wrong for JPY (no minor unit at all) and for TND (three). Rather than
 * carry a table that will be incomplete, the exponent is asked of `Intl`, which
 * has one per currency — the same source the formatter itself uses, so the
 * divisor and the rendering can never disagree.
 *
 * There is no arithmetic here beyond that scaling. Totals, tax and discounts are
 * the server's: a screen that added two `Money` values would be doing money
 * arithmetic in JavaScript, which §4 puts in the Core and §25 puts under audit.
 */

export type Money = Schemas['Money'];

/**
 * How many digits this currency's minor unit has.
 *
 * `Intl` knows: EUR 2, JPY 0, TND 3. An unknown code falls back to 2, which is
 * what `Intl` itself does, so an exotic currency renders plausibly rather than
 * throwing on a screen whose job is to show a price.
 */
export function minorUnitDigits(currency: string, locale?: string): number {
  try {
    return (
      new Intl.NumberFormat(locale, { style: 'currency', currency }).resolvedOptions()
        .maximumFractionDigits ?? 2
    );
  } catch {
    // An invalid code — the contract requires three letters but this is display
    // code and must not be the thing that breaks the page.
    return 2;
  }
}

export function formatMoney(money: Money, locale?: string): string {
  const digits = minorUnitDigits(money.currency, locale);
  const amount = money.minor_units / 10 ** digits;

  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency: money.currency }).format(
      amount,
    );
  } catch {
    // Still says what it is, in the shape the API gave it.
    return `${amount.toFixed(digits)} ${money.currency}`;
  }
}

/**
 * A price.
 *
 * `data-minor-units` carries the integer the API sent, so a test can assert the
 * exact amount without depending on the runtime's locale data — and so anything
 * reading the DOM sees the authoritative value rather than a rendered string.
 */
export function Amount({ money, className }: { money: Money; className?: string }) {
  return (
    <span
      data-minor-units={money.minor_units}
      data-currency={money.currency}
      className={className}
    >
      {formatMoney(money)}
    </span>
  );
}

/**
 * A VAT rate, from the basis points the API stores.
 *
 * Basis points because a percentage in a string loses 5.5% to whatever the
 * client's parser does with the half — so the contract sends 550 and this
 * divides by 100 for display only. Trailing zeros are trimmed, so 2000 reads as
 * "20%" rather than "20.00%", and 550 as "5.5%".
 */
export function formatVatRate(basisPoints: number): string {
  const percent = basisPoints / 100;

  return `${String(Number(percent.toFixed(2)))}%`;
}
