<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * How long a product's freemium runs (spec §6.3).
 *
 * **Days, and therefore not a term.** `term_months` cannot say five days, and
 * §6.3 weighs the two ways of fixing that: a `trial_days` column on
 * `subscriptions`, or `current_period_end = now() + interval` written at
 * subscription with no term at all. It recommends the second, and this is the
 * interval. A freemium is then an ordinary subscription of a single period that
 * does not renew — no column, and nothing new for the clock to understand.
 *
 * **It is configuration and not a constant.** Five days is the demonstration's
 * answer, not the platform's: how long a product gives itself away is a
 * commercial decision of whoever sells it, and a number compiled into PHP is
 * one an operator cannot change without a release. So it lives where a
 * product's other operator-owned facts live — `product_configuration`, beside
 * the billing supplier (ADR-044) and the tax position — and the key is named by
 * the code that reads it, which is this class.
 *
 * **Absent means the product offers no freemium**, and the door refuses rather
 * than guessing a duration. Fail closed: a guessed interval is a free period
 * nobody agreed to give, and the one direction that cannot be taken back is
 * giving too much of it away.
 */
final class FreemiumPeriod
{
    /**
     * @see \App\Product\Domain\ProductSettings — keys are named by whoever
     *      reads them, so a typo stores configuration nothing consults.
     */
    public const CONFIGURATION_KEY = 'freemium';

    /**
     * A year and a day is not a trial. The ceiling is not arithmetic caution —
     * `+9999 days` computes fine — it is that a mistyped figure is a product
     * given away for a decade and nothing on any screen would say so.
     */
    public const LONGEST = 366;

    private function __construct(public readonly int $days)
    {
    }

    /**
     * The interval this product's configuration sells, or null when it sells
     * none — including when the stored document is unusable, because a
     * configured nonsense and no configuration at all deserve the same answer:
     * nothing is given away on a value nobody can read.
     *
     * @param array<string, mixed> $configuration every setting of the product, decoded
     */
    public static function fromConfiguration(array $configuration): ?self
    {
        $document = $configuration[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($document)) {
            return null;
        }

        $days = $document['days'] ?? null;

        if (!is_int($days) || $days < 1 || $days > self::LONGEST) {
            return null;
        }

        return new self($days);
    }

    /** For a caller that has the number already — the seeder, and tests. */
    public static function ofDays(int $days): self
    {
        return new self($days);
    }

    /** @return array<string, int> the document as `product_configuration` stores it */
    public function asConfiguration(): array
    {
        return ['days' => $this->days];
    }

    public function endsFrom(DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->modify(sprintf('+%d days', $this->days));
    }
}
