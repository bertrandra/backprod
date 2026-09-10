import { describe, expect, it } from 'vitest';

import { formatMoney, formatVatRate, minorUnitDigits } from './Money';

/**
 * Money on screen, and the assumption this file exists to avoid.
 *
 * Dividing minor units by 100 is right for EUR and wrong for two currencies this
 * platform could plausibly meet. The tests below are the reason the divisor is
 * asked of `Intl` rather than written as `/ 100`.
 */
describe('minor units', () => {
  it('are two for EUR', () => {
    expect(minorUnitDigits('EUR')).toBe(2);
  });

  it('are none for JPY', () => {
    // ¥2900 is 2900 yen, not 29. A hard-coded /100 would under-report by a
    // factor of a hundred on every Japanese price.
    expect(minorUnitDigits('JPY')).toBe(0);
  });

  it('are three for TND', () => {
    // The Tunisian dinar has millimes. A /100 divisor would over-report tenfold.
    expect(minorUnitDigits('TND')).toBe(3);
  });

  it('fall back to two for a code Intl does not know', () => {
    // Display code must not be the thing that breaks the page.
    expect(minorUnitDigits('ZZZ')).toBe(2);
  });
});

describe('formatting an amount', () => {
  it('scales by the currency, not by a constant', () => {
    const euros = formatMoney({ minor_units: 2900, currency: 'EUR' }, 'en-GB');
    const yen = formatMoney({ minor_units: 2900, currency: 'JPY' }, 'en-GB');

    // Same integer, two different amounts of money, because the currencies have
    // different minor units.
    expect(euros).toMatch(/29\.00/);
    expect(yen).toMatch(/2,900/);
    expect(euros).not.toBe(yen);
  });

  it('renders a free price as a price, not as nothing', () => {
    // Zero is legitimate — a free tier is still an offer — and must not read as
    // an absent price.
    expect(formatMoney({ minor_units: 0, currency: 'EUR' }, 'en-GB')).toMatch(/0\.00/);
  });

  it('never renders a bare number without saying what it is', () => {
    const formatted = formatMoney({ minor_units: 150, currency: 'GBP' }, 'en-GB');

    expect(formatted).not.toBe('1.50');
    expect(formatted).toMatch(/£|GBP/);
  });

  it('still says something for a currency code Intl rejects', () => {
    const formatted = formatMoney({ minor_units: 1234, currency: 'ZZ' }, 'en-GB');

    expect(formatted).toContain('ZZ');
  });
});

describe('a VAT rate', () => {
  it('reads 2000 basis points as 20%', () => {
    expect(formatVatRate(2000)).toBe('20%');
  });

  it('keeps the half in 5.5%', () => {
    // The whole reason the API sends basis points: "20%" and "5.5%" through a
    // careless parser are not the same kind of number.
    expect(formatVatRate(550)).toBe('5.5%');
  });

  it('reads zero as zero', () => {
    expect(formatVatRate(0)).toBe('0%');
  });
});
