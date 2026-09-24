<?php

declare(strict_types=1);

namespace App\Product\Domain;

use DateTimeImmutable;

/**
 * A picture a product shows on its own page.
 *
 * `contentType` is what the bytes were sniffed to be, never what the
 * upload claimed — the same rule `UploadPolicy` already enforces, and the
 * reason a PHP script announcing itself as `image/png` gets nowhere.
 * `filename` is the opposite: a label the operator chose, kept to hand
 * back and never used to address anything.
 */
final class ProductAsset
{
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $storageKey,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly int $byteSize,
        public readonly string $checksum,
        public readonly ?string $uploadedBy,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
