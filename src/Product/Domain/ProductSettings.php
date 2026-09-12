<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * A product's configuration, readable and writable by the platform.
 *
 * A fourth port beside {@see ProductRepository}, {@see ProductRegistry} and
 * {@see ProductDirectory}, and separate from all three for the reason the
 * others are separate from each other:
 *
 *   - `ProductRegistry::configuration()` answers the same question, but that
 *     port's other methods are membership-shaped — "which products may *this
 *     person* reach" — and a platform role grants no membership (non-negotiable
 *     #22). A console asking it would be asking a port built for the tenant
 *     shell;
 *   - `ProductDirectory` is the set of products and the acts that change it. A
 *     product's settings are not that set.
 *
 * **This is the port that made a new product sellable.** ADR-042 gave the
 * console a way to create a product and ADR-043 a way to price it, and a
 * checkout against it still refused with `BILLING_NOT_CONFIGURED`, because the
 * legal identity an invoice must name lives in this table and exactly one thing
 * on the platform wrote it — the demo seeder.
 *
 * Keys are named by the code that reads them ({@see
 * \App\Billing\Domain\SupplierDetails::CONFIGURATION_KEY},
 * {@see \App\Tax\Domain\SupplierTaxSettings::CONFIGURATION_KEY}) rather than
 * chosen by a caller. A free-form key writer would let a typo store
 * configuration nothing reads, which looks exactly like configuration that did
 * not save.
 */
interface ProductSettings
{
    /**
     * Every setting of a product, decoded.
     *
     * @return array<string, mixed> configuration keyed by setting name
     */
    public function all(string $productId): array;

    /**
     * Writes one setting, replacing whatever was there.
     *
     * Replacing rather than merging, per key: each key is one document with
     * its own shape, and a merge would let a field removed on purpose — a VAT
     * number the supplier no longer has — survive because the new document
     * does not mention it.
     *
     * @param array<string, mixed> $value
     */
    public function put(string $productId, string $key, array $value): void;
}
