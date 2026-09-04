<?php

declare(strict_types=1);

namespace App\Storage\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Storage\Domain\Asset;
use App\Storage\Domain\AssetRepository;
use App\Storage\Domain\StorageProvider;

/**
 * Uploading, listing and removing assets.
 *
 * The order of operations in {@see self::upload()} is deliberate: the bytes go
 * to the store first, and the row is written second. The two can disagree
 * either way and only one of the two is survivable — an object with no row is
 * an orphan nobody can reach, while a row with no object is a broken download
 * that looks fine in a listing. Orphans are the safer failure, and a sweep can
 * find them later; the reverse cannot be fixed at all.
 */
final class Assets
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly StorageProvider $storage,
        private readonly UploadPolicy $policy,
    ) {
    }

    public function upload(
        string $tenantId,
        string $productId,
        ?string $projectId,
        string $contents,
        string $filename,
        ?string $uploadedBy,
    ): Asset {
        // Sniffed, sized and hashed before anything is written, so a refused
        // upload leaves no trace in the store.
        $inspected = $this->policy->inspect($contents);

        $key = self::newKey();
        $this->storage->put($key, $contents);

        return $this->assets->record(
            $tenantId,
            $productId,
            $projectId,
            Asset::UPLOAD,
            $key,
            $this->policy->safeFilename($filename),
            $inspected['content_type'],
            $inspected['byte_size'],
            $inspected['checksum'],
            $uploadedBy,
        );
    }

    /**
     * Stores something the platform produced rather than something a client
     * sent — an export. The bytes are ours, so there is nothing to sniff for
     * safety; the type is stated by the producer.
     */
    public function store(
        string $tenantId,
        string $productId,
        string $projectId,
        string $contents,
        string $filename,
        string $contentType,
    ): Asset {
        $key = self::newKey();
        $this->storage->put($key, $contents);

        return $this->assets->record(
            $tenantId,
            $productId,
            $projectId,
            Asset::EXPORT,
            $key,
            $this->policy->safeFilename($filename),
            $contentType,
            strlen($contents),
            hash('sha256', $contents),
            null,
        );
    }

    /**
     * @return array{assets: list<Asset>, total: int, limit: int, offset: int}
     */
    public function list(
        string $tenantId,
        string $productId,
        ?string $projectId,
        int $limit,
        int $offset,
    ): array {
        return [
            'assets' => $this->assets->listFor($tenantId, $productId, $projectId, $limit, $offset),
            'total' => $this->assets->countFor($tenantId, $productId, $projectId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $assetId): Asset
    {
        $asset = $this->assets->find($tenantId, $productId, $assetId);

        if ($asset === null) {
            throw new NotFoundException('Asset not found.', [], 'ASSET_NOT_FOUND');
        }

        return $asset;
    }

    /**
     * The asset a signed link names.
     *
     * Unscoped by tenant, which is safe only because the signature has
     * already proved the bearer was handed this exact asset by someone who
     * could see it — and because a link is followed by a browser with no
     * tenant context to resolve. Nothing but the download route may call it.
     */
    public function forSignedLink(string $assetId): Asset
    {
        $asset = $this->assets->findForSignedLink($assetId);

        if ($asset === null) {
            throw new NotFoundException('Asset not found.', [], 'ASSET_NOT_FOUND');
        }

        return $asset;
    }

    /**
     * The bytes, for a request that has already proved it may have them.
     */
    public function contentsOf(Asset $asset): string
    {
        return $this->storage->get($asset->storageKey);
    }

    public function delete(string $tenantId, string $productId, string $assetId): void
    {
        $asset = $this->show($tenantId, $productId, $assetId);

        // The row goes first here, the reverse of upload, and for the same
        // reason: if the object outlives the row it is unreachable, whereas a
        // row pointing at a deleted object is a download that fails.
        $this->assets->delete($asset);
        $this->storage->delete($asset->storageKey);
    }

    /**
     * An opaque key, from randomness rather than from anything a client sent.
     *
     * Never derived from the filename: a key built from user input is a path
     * traversal waiting to be written, and a collision away from one upload
     * overwriting another.
     */
    private static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
