<?php

declare(strict_types=1);

namespace App\Product\Service;

use App\Product\Domain\ProductAsset;
use App\Product\Domain\ProductAssets;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\UnprocessableEntityException;
use App\Storage\Domain\StorageProvider;
use App\Storage\Service\UploadPolicy;

/**
 * Putting a picture on a product's page, and serving it (2026-09-24).
 *
 * **The policy is the storage module's**, unchanged and reused: the bytes
 * are sniffed and the sniffed type is what is stored and served, never
 * what the request claimed. A PHP script announcing itself as `image/png`
 * is the oldest upload bug there is, and this page is the one a stranger's
 * browser renders — so it gets the same defence every other upload does,
 * plus one more.
 *
 * That one more is **pictures only**. The shared policy admits PDFs, CSVs
 * and zips because a project's assets are files somebody downloads; a
 * showcase asset is drawn into an `<img>` on a public page, and there is
 * no such thing as a `<img src="…zip">`. Refused here rather than left to
 * `product_assets_is_a_picture`, which would answer with a constraint's
 * name.
 */
final class ShowcasePictures
{
    /**
     * What a browser will actually draw. SVG is absent for the reason
     * `UploadPolicy` gives: an image to a person, a script container to a
     * browser, and this one is served from the platform's own origin.
     *
     * @var list<string>
     */
    private const PICTURES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ProductAssets $assets,
        private readonly StorageProvider $storage,
        private readonly UploadPolicy $policy,
    ) {
    }

    public function upload(
        string $productId,
        string $contents,
        string $filename,
        ?string $uploadedBy,
    ): ProductAsset {
        $inspected = $this->policy->inspect($contents);

        if (!in_array($inspected['content_type'], self::PICTURES, true)) {
            throw new UnprocessableEntityException(
                'NOT_A_PICTURE',
                'A product page shows pictures. This is not one.',
                ['content_type' => $inspected['content_type'], 'allowed' => self::PICTURES],
            );
        }

        // Bytes first, row second: an object with no row is an orphan a
        // sweep can find, while a row with no object is a broken picture
        // that looks fine in a listing.
        $key = bin2hex(random_bytes(16));
        $this->storage->put($key, $contents);

        return $this->assets->record(
            $productId,
            $key,
            $this->policy->safeFilename($filename),
            $inspected['content_type'],
            $inspected['byte_size'],
            $inspected['checksum'],
            $uploadedBy,
        );
    }

    /**
     * @return list<ProductAsset>
     */
    public function of(string $productId): array
    {
        return $this->assets->of($productId);
    }

    /** The picture behind a public page, or nothing at all. */
    public function published(string $code, string $assetId): ProductAsset
    {
        $asset = $this->assets->published($code, $assetId);

        if ($asset === null) {
            throw new NotFoundException('No such picture.', [], 'ASSET_NOT_FOUND');
        }

        return $asset;
    }

    public function contentsOf(ProductAsset $asset): string
    {
        return $this->storage->get($asset->storageKey);
    }

    public function delete(string $productId, string $assetId): void
    {
        $asset = $this->assets->find($productId, $assetId);

        if ($asset === null) {
            throw new NotFoundException('No such picture.', [], 'ASSET_NOT_FOUND');
        }

        // The row first, the reverse of upload and for the same reason. The
        // block that showed it keeps its words: the foreign key is
        // `ON DELETE SET NULL`, so tidying an image never deletes a caption.
        $this->assets->delete($asset);
        $this->storage->delete($asset->storageKey);
    }
}
