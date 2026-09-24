<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * The pictures a product shows on its own page (2026-09-24, step 4 of
 * `docs/home-showcase-spec.md`).
 *
 * A port beside `App\Storage\Domain\AssetRepository` rather than a use of
 * it: that one is **tenant-scoped by construction** — every read is
 * narrowed by `tenant_id`, which is what makes holding a stray asset id
 * useless — and a shop window belongs to no tenant. Widening it would mean
 * a nullable tenant and an isolation check that is sometimes skipped,
 * which is the shape of the defect the scoping exists to prevent.
 */
interface ProductAssets
{
    /**
     * Records a picture the platform has already written to storage.
     *
     * Bytes first and the row second, the same order {@see
     * \App\Storage\Service\Assets} uses and for the same reason: an object
     * with no row is an orphan a sweep can find, while a row with no object
     * is a broken picture that looks fine in a listing.
     */
    public function record(
        string $productId,
        string $storageKey,
        string $filename,
        string $contentType,
        int $byteSize,
        string $checksum,
        ?string $uploadedBy,
    ): ProductAsset;

    /**
     * Every picture of one product, newest first. For the console.
     *
     * @return list<ProductAsset>
     */
    public function of(string $productId): array;

    /**
     * One picture, only while the product's story is published.
     *
     * The condition is the whole authorisation: the page is public, so its
     * pictures are, and the moment the page comes down they stop being
     * served. Null for an unpublished product, an unknown id and a picture
     * of another product alike — one non-answer, so an id is not a way to
     * ask what a draft contains.
     */
    public function published(string $code, string $assetId): ?ProductAsset;

    /** One picture of one product, for the console. */
    public function find(string $productId, string $assetId): ?ProductAsset;

    public function delete(ProductAsset $asset): void;
}
