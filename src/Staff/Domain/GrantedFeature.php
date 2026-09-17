<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * One feature in a grant: its code and, for a quota, the limit — null for
 * unlimited, exactly as an offer version's grant carries it.
 */
final class GrantedFeature
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?int $limit,
    ) {
    }
}
