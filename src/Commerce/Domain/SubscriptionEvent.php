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
