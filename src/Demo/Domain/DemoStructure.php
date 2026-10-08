<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * The ids the fixtures handed out, so the seeder can run the invariants
 * against the rows it just wrote without asking the database for them by
 * name.
 */
final class DemoStructure
{
    /**
     * @param array<string, string>                $products product code => id
     * @param array<string, string>                $tenants  tenant key => id
     * @param array<string, string>                $users    person key => id
     * @param array<string, array<string, string>> $offers   product code => offer code => id
     */
    public function __construct(
        public readonly array $products,
        public readonly array $tenants,
        public readonly array $users,
        public readonly array $offers,
        /**
         * The showcase blocks written with a picture named, as
         * `[block id, file name]` pairs, in the order they were written
         * (2026-10-08). The fixtures cannot upload — that is the showcase
         * module's door — so they say which rows are waiting for one.
         *
         * @var list<array{0: string, 1: string}>
         */
        public readonly array $pictures = [],
    ) {
    }

    public function product(string $code): string
    {
        return $this->products[$code] ?? throw new \RuntimeException("The demo has no product {$code}.");
    }

    public function tenant(string $key): string
    {
        return $this->tenants[$key] ?? throw new \RuntimeException("The demo has no tenant {$key}.");
    }

    public function user(string $key): string
    {
        return $this->users[$key] ?? throw new \RuntimeException("The demo has no person {$key}.");
    }

    public function offer(string $product, string $code): string
    {
        return $this->offers[$product][$code] ?? throw new \RuntimeException("The demo has no offer {$code} on {$product}.");
    }
}
