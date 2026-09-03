<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

use DateTimeImmutable;

/**
 * Somebody's place in a thread, and how far they have read.
 *
 * `lastReadSeq` is a watermark rather than a set of read messages: one number
 * per participant answers "what is new for me" without a row per message per
 * reader, and it only ever moves forward.
 *
 * `leftAt` exists because participants are never deleted. Their messages keep
 * their author, and the foreign key from `messages` would refuse the delete
 * in any case.
 */
final class Participant
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $userId,
        public readonly string $participantKind,
        public readonly int $lastReadSeq,
        public readonly DateTimeImmutable $joinedAt,
        public readonly ?DateTimeImmutable $leftAt,
    ) {
    }

    public function hasLeft(): bool
    {
        return $this->leftAt !== null;
    }

    public function isStaff(): bool
    {
        return $this->participantKind === ParticipantKind::STAFF;
    }
}
