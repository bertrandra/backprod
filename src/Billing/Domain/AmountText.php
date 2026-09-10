<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Money and tax rates as a human reads them.
 *
 * Separate from whatever renders a document, because the arithmetic is the
 * part worth testing and a PDF is the worst place to assert against: the
 * streams are compressed, so a test that read the bytes back would prove
 * nothing about the number that went in.
 *
 * French conventions — comma for the decimal mark, a space between thousands
 * and before the percent sign — because the documents this formats are French
 * invoices. A second locale would make this a locale-aware formatter; there is
 * one, so it is not.
 */
final class AmountText
{
    /**
     * Currencies with no minor unit, where 1000 means 1000 and not 10.00.
     * ISO 4217 also has three-decimal currencies; none of them is billable
     * here yet, and inventing a rule for a case nobody has is how a wrong one
     * arrives unnoticed.
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF'];

    /**
     * A narrow no-break space, so a total never wraps between the digits and
     * the currency.
     */
    private const THIN_SPACE = "\u{202F}";

    /**
     * The integer is split rather than divided: `1999 / 100` is a float, and a
     * float is how an invoice total ends up a hundredth out.
     */
    public static function money(Money $money): string
    {
        $currency = strtoupper($money->currency);
        $units = $money->minorUnits;
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            $amount = self::grouped($units);
        } else {
            $amount = self::grouped(intdiv($units, 100))
                . ',' . str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT);
        }

        return $sign . $amount . self::THIN_SPACE . $currency;
    }

    /**
     * Basis points to a percentage: 2000 becomes `20 %`, 550 becomes `5,5 %`,
     * 2050 becomes `20,5 %`. Trailing zeros go, because `20,00 %` invites the
     * reader to look for a precision that is not there.
     */
    public static function rate(int $basisPoints): string
    {
        $sign = $basisPoints < 0 ? '-' : '';
        $basisPoints = abs($basisPoints);

        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        if ($fraction === 0) {
            return $sign . $whole . self::THIN_SPACE . '%';
        }

        $digits = rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');

        return $sign . $whole . ',' . $digits . self::THIN_SPACE . '%';
    }

    /**
     * Thousands separated by a no-break space, without `number_format` — which
     * takes a float and would round a total that does not fit one exactly.
     */
    private static function grouped(int $value): string
    {
        $digits = (string) $value;
        $out = '';

        for ($i = strlen($digits); $i > 0; $i -= 3) {
            $start = max(0, $i - 3);
            $chunk = substr($digits, $start, $i - $start);
            $out = $out === '' ? $chunk : $chunk . "\u{00A0}" . $out;
        }

        return $out;
    }
}
