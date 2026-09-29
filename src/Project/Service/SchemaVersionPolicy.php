<?php

declare(strict_types=1);

namespace App\Project\Service;

use App\Product\Domain\ProductRegistry;
use App\Project\Domain\SchemaVersions;
use App\Shared\Exceptions\UnprocessableEntityException;

/**
 * Which document schema versions a product accepts (non-negotiable #10).
 *
 * The list is per-product configuration, not a constant here. That is the
 * whole point of M3: a second product declares its own accepted versions as a
 * row, and this code never learns either product's name. A hard-coded list
 * would have been the first product-specific branch (§12.1).
 *
 * Configured under the key `project_schema_versions`:
 *
 *     {"supported": [1, 2]}
 *
 * A product that has declared nothing supports nothing, and its projects
 * cannot be written. That is the intended failure: a product whose accepted
 * schema versions are unknown must not accept documents on the assumption
 * that whatever arrives is fine — the same reasoning that seeds entitlements
 * empty rather than open.
 *
 * **What a valid list is lives in {@see SchemaVersions}**, not here, since the
 * console gained a way to write one (2026-09-29). Reading stays tolerant and
 * writing is strict, and both have to mean the same thing by "a version" or a
 * product would display one list and enforce another.
 */
final class SchemaVersionPolicy
{
    public const CONFIGURATION_KEY = 'project_schema_versions';

    public function __construct(private readonly ProductRegistry $products)
    {
    }

    /**
     * @return list<int>
     */
    public function supportedFor(string $productId): array
    {
        return SchemaVersions::read(
            $this->products->configuration($productId)[self::CONFIGURATION_KEY] ?? null,
        );
    }

    public function assertSupported(string $productId, int $schemaVersion): void
    {
        $supported = $this->supportedFor($productId);

        if (in_array($schemaVersion, $supported, true)) {
            return;
        }

        // The supported list is returned because it is the answer to the
        // client's next question, and it discloses nothing beyond this
        // product's own configuration — which the caller can already read.
        throw new UnprocessableEntityException(
            'UNSUPPORTED_SCHEMA_VERSION',
            'This product does not accept project documents of that schema version.',
            ['schema_version' => $schemaVersion, 'supported' => $supported],
        );
    }
}
