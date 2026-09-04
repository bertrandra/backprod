<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;

/**
 * A recorded, revocable opt-in, with its proof (§27.1, §26.1).
 *
 * `evidence` and `source` are not decoration: consent that cannot be
 * evidenced is consent that cannot be relied on, and the question asked later
 * is always "when, and on what basis?".
 *
 * Revocation is a date rather than a deletion. Erasing the row would destroy
 * the record that permission once existed, which is the opposite of what
 * proof means.
 */
final class Consent
{
    public const TRANSACTIONAL = 'TRANSACTIONAL';
    public const MARKETING = 'MARKETING';

    /**
     * @param array<string, mixed> $evidence
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly DateTimeImmutable $grantedAt,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly string $source,
        public readonly array $evidence,
    ) {
    }

    public function isLive(): bool
    {
        return $this->revokedAt === null;
    }
}
