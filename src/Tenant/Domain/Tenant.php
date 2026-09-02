<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

final class Tenant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
    ) {
    }
}
