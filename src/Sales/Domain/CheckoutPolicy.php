<?php

declare(strict_types=1);

namespace App\Sales\Domain;

/**
 * How long a checkout may wait for its payment before it is given up (ADR-079).
 *
 * A closed tab used to leave an order `AWAITING_PAYMENT` for ever, with an
 * issued invoice behind it and that invoice's VAT in the organisation's return.
 * Nothing was owed — nobody had bought anything — and nothing ever said so.
 *
 * **The product's decision, read from `product_configuration`** under the key
 * this class names, never a constant: how long a seller leaves an unpaid
 * purchase open is theirs to choose, and a number compiled into PHP is one an
 * operator cannot change without a release.
 *
 * **Silence is two days.** Unlike the free period, there is an honest default
 * here: leaving an abandoned checkout open costs the seller a wrong figure in a
 * tax return, and closing one gives nothing away — the customer buys again with
 * one click. Forty-eight hours is longer than any card authentication and any
 * weekend's second thoughts, and short enough that a month's return is not
 * closed over it. An unreadable document reads as the default, like the
 * collection schedule does.
 */
final class CheckoutPolicy
{
    /**
     * @see \App\Product\Domain\ProductSettings — keys are named by whoever
     *      reads them, so a typo stores configuration nothing consults.
     */
    public const CONFIGURATION_KEY = 'checkout';

    public const DEFAULT_ABANDON_AFTER_HOURS = 48;

    /** An hour is the pass's own grain; a month is no longer a checkout. */
    public const SHORTEST = 1;
    public const LONGEST = 720;

    private function __construct(public readonly int $abandonAfterHours)
    {
    }

    /**
     * @param array<string, mixed> $configuration every setting of the product, decoded
     */
    public static function fromConfiguration(array $configuration): self
    {
        $document = $configuration[self::CONFIGURATION_KEY] ?? null;
        $hours = is_array($document) ? ($document['abandon_after_hours'] ?? null) : null;

        if (!is_int($hours) || $hours < self::SHORTEST || $hours > self::LONGEST) {
            return new self(self::DEFAULT_ABANDON_AFTER_HOURS);
        }

        return new self($hours);
    }

    public function isAbandonedAfter(int $hoursWaiting): bool
    {
        return $hoursWaiting >= $this->abandonAfterHours;
    }
}
