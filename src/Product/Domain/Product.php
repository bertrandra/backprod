<?php

declare(strict_types=1);

namespace App\Product\Domain;

final class Product
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $active,
    ) {
    }
}
