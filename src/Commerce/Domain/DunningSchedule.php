<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * When a product chases an unpaid invoice (spec §5.2).
 *
 * **It is configuration and not a constant** (decided 2026-09-26). A constant
 * would make changing a commercial delay a deployment, and two products have no
 * reason to chase at the same rhythm — a tool sold to consumers and one sold to
 * municipalities have different customers and different patience. So the
 * schedule lives where a product's other operator-owned facts live,
 * `product_configuration`, beside the billing supplier (ADR-044) and the free
 * period's length ({@see FreemiumPeriod}), and the key is named by the code that
 * reads it, which is this class.
 *
 * ```json
 * {"retries": [1, 3, 7]}
 * ```
 *
 * Days after the invoice fell due. Three chases, then nothing.
 *
 * **Absent means the default, and this is the opposite choice from
 * {@see FreemiumPeriod}** — which refuses to act at all when a product has not
 * said, because guessing there gives something away and giving away is the
 * direction that cannot be taken back. Here the unconfigured direction is the
 * other way round: failing closed would mean never chasing an unpaid invoice,
 * which loses the money the platform is owed and leaves the customer's product
 * working for free. Chasing a debt is not a gift, so a product that has said
 * nothing gets the sensible schedule, and the fact that it is a *default*
 * rather than a rule is the whole point.
 *
 * **The first chase is also the grace, and that is deliberate.** Spec §5.1
 * dates arrears from the invoice's due date; every invoice this platform raises
 * says "payable on receipt", so due is *now* and declaring arrears at the due
 * date would suspend the workshop of a customer in the middle of paying — an
 * upgrade's proration invoice is issued and settled seconds apart. One number
 * answers both questions instead of two numbers nearly answering one:
 * `retries[0]` is how long the operator waits before treating silence as
 * non-payment, and the wait is the chase.
 */
final class DunningSchedule
{
    /**
     * @see \App\Product\Domain\ProductSettings — keys are named by whoever
     *      reads them, so a typo stores configuration nothing consults.
     */
    public const CONFIGURATION_KEY = 'dunning';

    /**
     * J+1, J+3, J+7, then stop (spec §5.2). A default and not a rule.
     *
     * @var list<int>
     */
    public const DEFAULT_RETRIES = [1, 3, 7];

    /**
     * A chase a year later is not a chase, and a schedule with forty of them
     * is harassment somebody typed by accident. Both ends are bounded so that
     * a mistyped document reads as unusable rather than as a policy.
     */
    public const LONGEST_DELAY = 180;
    public const MOST_RETRIES = 12;

    /**
     * @param list<int> $retries strictly increasing, days after the due date
     */
    private function __construct(public readonly array $retries)
    {
    }

    /**
     * This product's schedule, or the platform's default when it has none.
     *
     * An unusable document — not an array, empty, out of order, a day outside
     * the bounds — is treated exactly as no document at all. The alternative is
     * to chase on a value nobody can read, and a schedule nobody can read is
     * not a decision an operator made.
     *
     * @param array<string, mixed> $configuration every setting of the product, decoded
     */
    public static function fromConfiguration(array $configuration): self
    {
        $document = $configuration[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($document)) {
            return self::theDefault();
        }

        $retries = $document['retries'] ?? null;

        if (!is_array($retries) || $retries === []) {
            return self::theDefault();
        }

        $days = [];
        $previous = 0;

        foreach ($retries as $day) {
            if (!is_int($day) || $day <= $previous || $day > self::LONGEST_DELAY) {
                // Out of order is refused rather than sorted: a list somebody
                // meant as [7, 3, 1] and a list they mistyped are the same
                // bytes, and sorting would silently pick one reading.
                return self::theDefault();
            }

            $days[] = $day;
            $previous = $day;
        }

        if (count($days) > self::MOST_RETRIES) {
            return self::theDefault();
        }

        return new self($days);
    }

    public static function theDefault(): self
    {
        return new self(self::DEFAULT_RETRIES);
    }

    /**
     * For a caller that has the numbers already — the seeder, and tests.
     *
     * @param list<int> $retries
     */
    public static function ofDays(array $retries): self
    {
        return new self($retries);
    }

    /** @return array<string, list<int>> the document as `product_configuration` stores it */
    public function asConfiguration(): array
    {
        return ['retries' => $this->retries];
    }

    /**
     * How long the operator waits before silence counts as non-payment.
     */
    public function graceDays(): int
    {
        return $this->retries[0];
    }

    /**
     * Which chase is due for an invoice this many days overdue, 1-based, or
     * null when none is.
     *
     * The **highest** step whose day has passed, not the next one after the
     * last: a pass that has not run for a week must not work through three
     * chases in three minutes, which would arrive as three mails in one inbox
     * and look like a fault. Skipping to where the clock actually is means a
     * queue that fell behind catches up in one notice rather than in a burst.
     *
     * Past the last step it stays at the last step, which is how "then stop"
     * is implemented: the notice for that step has already been raised, the
     * dedup index refuses a second, and nothing further happens. Stopping is
     * the absence of a next step rather than a state anybody has to record.
     */
    public function stepDueAfter(int $daysOverdue): ?int
    {
        $step = null;

        foreach ($this->retries as $index => $day) {
            if ($daysOverdue >= $day) {
                $step = $index + 1;
            }
        }

        return $step;
    }
}
