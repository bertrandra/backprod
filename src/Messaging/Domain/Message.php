<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

use DateTimeImmutable;

/**
 * One message in a thread.
 *
 * `seq` orders the thread and anchors the read watermark. It is monotone
 * within a conversation and unique there, but deliberately not gapless:
 * unbroken numbering is a legal requirement for invoices (§25), and borrowing
 * that machinery here would impose a table lock on every chat message to
 * satisfy a rule that does not apply.
 *
 * A deleted message keeps its row and loses its body. The thread has to keep
 * its order, and unlike an invoice a message carries no retention obligation
 * — so RGPD erasure wins and the body is really gone.
 */
final class Message
{
    public function __construct(
        public readonly string $id,
        public readonly string $conversationId,
        public readonly int $seq,
        public readonly ?string $authorUserId,
        public readonly string $authorKind,
        public readonly string $body,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $editedAt,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
