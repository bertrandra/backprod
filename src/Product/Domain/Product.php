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
        /**
         * Where the product's screens live when they are not the platform's
         * own shell (ADR-051 §3): an https origin the shell sends a person
         * to with `?product=` appended. Null for a product inside the shell.
         */
        public readonly ?string $appUrl = null,
    ) {
    }
}
