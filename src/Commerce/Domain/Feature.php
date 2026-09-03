<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * A billable capability: max_projects, advanced_3d, api_access (§13).
 *
 * Distinct from a ProductFeature, which says what a product has built. This
 * says what a customer can buy. A product may ship something it does not
 * sell, and may sell something it has not shipped yet; one table cannot say
 * both.
 */
final class Feature
{
    public const BOOLEAN = 'BOOLEAN';
    public const QUOTA = 'QUOTA';

    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $unit,
    ) {
    }

    /**
     * Whether this is something you have a number of, rather than something
     * you either have or do not.
     */
    public function isQuota(): bool
    {
        return $this->kind === self::QUOTA;
    }
}
