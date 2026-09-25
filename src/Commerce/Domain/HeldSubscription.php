<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use App\Billing\Domain\Money;
use DateTimeImmutable;

/**
 * One row of "who in this organisation is subscribed to what" (2026-09-25).
 *
 * A read model, not a {@see Subscription}. The organisation screen asks a
 * question no single subscription can answer on its own — it needs the
 * *holder's* name beside the offer, and the number of places the offer sold
 * beside the number taken — so this carries the answer rather than making a
 * screen assemble it from three reads and get the arithmetic wrong.
 *
 * **Places count the owner**, exactly as {@see \App\Commerce\Service\SubscriptionPeople}
 * counts them: whoever bought the subscription is one of the people it
 * covers. A screen that showed "2 of 3" for an owner plus two colleagues
 * would be describing a quota the server does not enforce.
 */
final class HeldSubscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        /** `USER` for a seat, `TENANT` for an organisation's own — §13.1. */
        public readonly string $subscriberKind,
        /**
         * Who bought it. Null only for a row from before ownership was
         * recorded (2026-09-25 fixed the sales chain that left it empty), and
         * the screen says "nobody" rather than pretending.
         */
        public readonly ?string $holderUserId,
        public readonly ?string $holderName,
        public readonly ?string $holderEmail,
        public readonly string $offerName,
        public readonly string $planName,
        public readonly string $billingPeriod,
        public readonly Money $price,
        public readonly ?DateTimeImmutable $currentPeriodEnd,
        /**
         * How many people the offer sells, counting the holder. Null means
         * unlimited — a grant with no limit — and is a different fact from
         * one place, which is what an offer selling no `users` feature gives.
         */
        public readonly ?int $placesSold,
        /** The holder plus everybody they have added. */
        public readonly int $placesUsed,
    ) {
    }

    /** Live now: active, and inside the period that was paid for. */
    public function isLiveAt(DateTimeImmutable $moment): bool
    {
        return $this->status === Subscription::ACTIVE
            && ($this->currentPeriodEnd === null || $this->currentPeriodEnd > $moment);
    }
}
