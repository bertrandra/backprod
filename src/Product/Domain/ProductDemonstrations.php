<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * The demonstrations a product has, asked of the product itself
 * (2026-11-22).
 *
 * A port, like {@see ProductManifests}: the read leaves this host, and the
 * domain names what it wants rather than how it is fetched.
 */
interface ProductDemonstrations
{
    /**
     * The numbers that answered, each with the name the product gave it.
     *
     * A number that did not answer is simply absent — refused, missing and
     * unreachable are one fact to a list: nothing to offer. Never throws: a
     * product whose server is down has no demonstrations today, and the
     * screen that asked still opens.
     *
     * @return list<array{number: int, name: string}>
     */
    public function found(string $appUrl, DemonstrationSource $source): array;
}
