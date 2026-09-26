<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * A move to another offer that has not happened yet (spec §4).
 *
 * A downgrade changes nothing on the day it is chosen: the customer has paid
 * for the plan they are on until the end of the period, and taking it away
 * early would be selling them a month and then withdrawing it. So the choice
 * is recorded as an intention, and renewal is what applies it.
 *
 * It carries the **names**, not just the version id, because the one thing a
 * screen has to say — "you will move to Starter on 31 March" — needs a plan
 * a person recognises. The rank comes with them so the screen can order the
 * catalogue without ever reading a plan's name (§13, `gate:plans`).
 *
 * No grants here. What the arriving offer entitles is decided when it
 * arrives, from the version as it stands then, exactly as a subscription's
 * grants are; carrying them now would be showing a customer a promise this
 * object is not the authority for.
 */
final class PendingChange
{
    public function __construct(
        public readonly string $offerVersionId,
        public readonly string $offerId,
        public readonly string $offerCode,
        public readonly string $offerName,
        public readonly Plan $plan,
        public readonly DateTimeImmutable $effectiveAt,
        public readonly DateTimeImmutable $requestedAt,
        public readonly ?string $requestedBy,
    ) {
    }

    /**
     * Whether the change falls at or before a moment.
     *
     * The question renewal asks, and the same shape as
     * {@see Subscription::isDueToEndBy()} — the clock decides, never a
     * sweeping job having run.
     */
    public function isDueBy(DateTimeImmutable $moment): bool
    {
        return $this->effectiveAt <= $moment;
    }
}
