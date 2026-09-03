<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

use DateTimeImmutable;

/**
 * One thing a tenant may use, and how much of it.
 *
 * The kind constants are declared here rather than borrowed from the
 * catalogue's Feature. This module is what the §10.6 context chain depends
 * on, on every authenticated request, and it is deliberately narrow: making
 * it import the commerce module would drag the whole catalogue into the hot
 * path to spell two strings.
 *
 * A null limit means two different things and callers must not guess: for a
 * boolean capability there is nothing to count, and for a quota it means no
 * ceiling. isUnlimited() answers the second question so nobody answers it
 * with a bare null check.
 */
final class Entitlement
{
    public const BOOLEAN = 'BOOLEAN';
    public const QUOTA = 'QUOTA';

    public function __construct(
        public readonly string $featureCode,
        public readonly string $featureName,
        public readonly string $kind,
        public readonly ?string $unit,
        public readonly ?int $limit,
        public readonly string $source,
        public readonly ?DateTimeImmutable $validUntil,
    ) {
    }

    public function isQuota(): bool
    {
        return $this->kind === self::QUOTA;
    }

    public function isUnlimited(): bool
    {
        return $this->isQuota() && $this->limit === null;
    }
}
