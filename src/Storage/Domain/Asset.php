<?php

declare(strict_types=1);

namespace App\Storage\Domain;

use DateTimeImmutable;

/**
 * The record of an object that lives outside this database.
 *
 * `contentType` is what the bytes were sniffed to be, not what the upload
 * claimed. `filename` is the opposite — a label the client chose, kept only
 * to hand back on download, and never used to address anything.
 */
final class Asset
{
    public const UPLOAD = 'UPLOAD';
    public const EXPORT = 'EXPORT';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly ?string $projectId,
        public readonly string $kind,
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
