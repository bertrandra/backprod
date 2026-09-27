<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;
use stdClass;

/**
 * One thing that happened to a subscription.
 *
 * Append-only: non-negotiable #18 requires commercial history to be
 * auditable, and a record that can be edited afterwards is not a record. The
 * repository never updates or deletes these rows.
 *
 * An offer change carries both ends, so "what did they move from, and to?"
 * is answerable from the event alone rather than by reconstructing it from
 * timestamps.
 */
final class SubscriptionEvent
{
    public const ACTIVATED = 'ACTIVATED';
    public const OFFER_CHANGED = 'OFFER_CHANGED';
    public const CANCELLATION_SCHEDULED = 'CANCELLATION_SCHEDULED';
    public const CANCELLED = 'CANCELLED';
    public const RESUMED = 'RESUMED';
    public const RENEWED = 'RENEWED';
    public const EXPIRED = 'EXPIRED';

    /**
     * A move to a lower plan, asked for and waiting for the end of the paid
     * period (2026-09-27, spec §4) — and the same move withdrawn before it
     * arrived, which is a retention act before it is a technical one.
     *
     * Both are recorded, including the withdrawal: "I asked to go down to
     * Starter" against "we have no record of it" needs an arbiter, exactly
     * as a cancellation request does. When the change is finally applied the
     * event is an ordinary `OFFER_CHANGED` — because that is what happened.
     */
    public const CHANGE_SCHEDULED = 'CHANGE_SCHEDULED';
    public const CHANGE_CANCELLED = 'CHANGE_CANCELLED';

    /**
     * Suspended for an unpaid invoice, and reopened when it was paid
     * (2026-09-27, spec §5.1).
     *
     * Both, because the column only ever says where things stand *now*: a
     * subscription reopened in April says nothing about having been shut for
     * six days in March, and "why could I not reach my work last week?" is a
     * question a support engineer has to be able to answer. The invoice's id
     * is on the detail of each, so the two ends of one suspension name the
     * same document.
     */
    public const ARREARS_DECLARED = 'ARREARS_DECLARED';
    public const ARREARS_CLEARED = 'ARREARS_CLEARED';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $fromOfferVersionId,
        public readonly ?string $toOfferVersionId,
        public readonly ?string $actorUserId,
        public readonly stdClass $detail,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }
}
