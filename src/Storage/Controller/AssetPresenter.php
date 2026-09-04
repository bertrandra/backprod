<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Storage\Domain\Asset;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * What a client is told about an asset.
 *
 * `storage_key` is deliberately absent. It addresses the object inside the
 * provider, it is the one thing a signed link does not need, and disclosing
 * it would leak the shape of a store that clients have no business knowing.
 */
final class AssetPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'kind' => $asset->kind,
            'project_id' => $asset->projectId,
            'filename' => $asset->filename,
            'content_type' => $asset->contentType,
            'byte_size' => $asset->byteSize,
            'checksum' => $asset->checksum,
            'uploaded_by' => $asset->uploadedBy,
            'created_at' => self::moment($asset->createdAt),
        ];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
