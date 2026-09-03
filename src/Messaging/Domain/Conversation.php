<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

use DateTimeImmutable;

/**
 * A thread, belonging to one tenant and one product.
 *
 * It names both for the same reason every other resource does: the product is
 * the root context (§12.1), and a conversation that named only a tenant would
 * be readable from any product that tenant uses.
 */
final class Conversation
{
    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $status,
        public readonly ?string $createdBy,
        public readonly ?DateTimeImmutable $closedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isSupport(): bool
    {
        return $this->kind === ConversationKind::SUPPORT;
    }
}
