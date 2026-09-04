<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * What a subscription commits to (§13.1).
 *
 * Carried by the offer version — that is what is sold — and copied into the
 * subscription as values when it is taken out. A repriced or re-termed offer
 * must not change one condition a customer already agreed to: the invoice
 * snapshot rule (§25) applied to the contract.
 *
 * The distinction this object exists to hold is the one §13.1 says costs the
 * most to lose:
 *
 *     the payment period is not the commitment
 *
 * A 24-month subscription billed monthly is *one* 24-month commitment billed
 * 24 times, not 24 one-month subscriptions that happen to follow each other.
 */
final class SubscriptionTerms
{
    public const ANYTIME = 'ANYTIME';
    public const AT_COMMITMENT_END = 'AT_COMMITMENT_END';
    public const AT_TERM = 'AT_TERM';

    public const AUTO_RENEW = 'AUTO_RENEW';
    public const ENDS_AT_TERM = 'ENDS_AT_TERM';

    public const FORBIDDEN = 'FORBIDDEN';
    public const CHARGE_REMAINING = 'CHARGE_REMAINING';
    public const FREE = 'FREE';

    public function __construct(
        public readonly ?int $termMonths,
        public readonly int $commitmentMonths,
        public readonly string $cancellationPolicy,
        public readonly string $renewal,
        public readonly string $earlyTermination,
        public readonly int $noticeDays,
    ) {
    }

    /**
     * Month-to-month with no commitment — what every subscription sold before
     * §13.1 amounts to, stated rather than implied.
     */
    public static function openEnded(): self
    {
        return new self(null, 0, self::ANYTIME, self::AUTO_RENEW, self::FORBIDDEN, 0);
    }

    public function hasCommitment(): bool
    {
        return $this->commitmentMonths > 0;
    }

    /**
     * When the commitment ends, counted from the start.
     *
     * Null when there is none — and the database refuses a commitment date
     * without a commitment, so the two cannot drift apart.
     */
    public function commitmentEndsFrom(DateTimeImmutable $start): ?DateTimeImmutable
    {
        return $this->hasCommitment()
            ? $start->modify(sprintf('+%d months', $this->commitmentMonths))
            : null;
    }

    public function termEndsFrom(DateTimeImmutable $start): ?DateTimeImmutable
    {
        return $this->termMonths === null
            ? null
            : $start->modify(sprintf('+%d months', $this->termMonths));
    }

    /**
     * @return list<string>
     */
    public static function policies(): array
    {
        return [self::ANYTIME, self::AT_COMMITMENT_END, self::AT_TERM];
    }
}
