<?php

declare(strict_types=1);

namespace App\Storage\Domain;

interface AssetRepository
{
    public function record(
        string $tenantId,
        string $productId,
        ?string $projectId,
        string $kind,
        string $storageKey,
        string $filename,
        string $contentType,
        int $byteSize,
        string $checksum,
        ?string $uploadedBy,
    ): Asset;

    /**
     * Always scoped by tenant and product: an asset id is a UUID a client
     * could hold from elsewhere, and the scope is what makes holding one
     * useless.
     */
    public function find(string $tenantId, string $productId, string $assetId): ?Asset;

    /**
     * Unscoped, for the signed-link download only.
     *
     * The signature has already proved the bearer was handed this exact asset
     * by someone who could see it, and a signed link is followed by a browser
     * with no tenant context to resolve. Nothing else may call this.
     */
    public function findForSignedLink(string $assetId): ?Asset;

    /**
     * @return list<Asset>
     */
    public function listFor(string $tenantId, string $productId, ?string $projectId, int $limit, int $offset): array;

    public function countFor(string $tenantId, string $productId, ?string $projectId): int;

    public function delete(Asset $asset): void;
}
