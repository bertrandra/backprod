<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Exceptions\ConflictException;

/**
 * An amount of money: a count of the currency's smallest unit, and which
 * currency that is.
 *
 * Integers throughout. A price is summed, compared, taxed and invoiced, and
 * floating point is wrong for all four — the error does not show up in a test
 * with two lines, it shows up in an audit of a year of them.
 *
 * Minor units rather than "cents", because not every currency has cents.
 */
final class Money
{
    private function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {
    }

    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->minorUnits * $quantity, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    /**
     * Tax at a rate in basis points — 2000 is 20%.
     *
     * Basis points rather than a percentage or a decimal: a rate of 5.5%,
     * which France charges on several things, is 550 exactly and 0.055
     * approximately. The rounding is half-up on the absolute value, which is
     * what invoices conventionally do and, more importantly, is one rule
     * applied in one place rather than whatever each call site reached for.
     */
    public function taxedAt(int $basisPoints): self
    {
        $product = $this->minorUnits * $basisPoints;
        $sign = $product < 0 ? -1 : 1;

        return new self(intdiv(abs($product) + 5_000, 10_000) * $sign, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            // Not a client error and not silently coercible: adding EUR to
            // USD is a bug, and the only honest exchange rate is one nobody
            // has supplied.
            throw new ConflictException(
                'CURRENCY_MISMATCH',
                'Amounts in different currencies cannot be combined.',
                ['expected' => $this->currency, 'actual' => $other->currency],
            );
        }
    }
}
