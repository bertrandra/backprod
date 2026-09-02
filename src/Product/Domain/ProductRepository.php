<?php

declare(strict_types=1);

namespace App\Product\Domain;

interface ProductRepository
{
    public function findByCode(string $code): ?Product;
}
