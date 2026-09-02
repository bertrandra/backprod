<?php

declare(strict_types=1);

namespace App\Project\Domain;

use App\Shared\Exceptions\PayloadTooLargeException;
use App\Shared\Exceptions\UnprocessableEntityException;

/**
 * What a project document may contain.
 *
 * Non-negotiable #9: large assets stay out of PostgreSQL. A JSONB column will
 * happily accept a base64 image, and nothing about it fails until the table
 * is unbackupable and every read of a project drags a megabyte of texture
 * across the wire. So it is refused here, at the boundary, with an error that
 * says what to do instead.
 *
 * The refusal is deliberately explicit rather than a size limit alone: a
 * small embedded asset is still an asset in the wrong place, and a client
 * that learns this on their first 40 KB thumbnail will not discover it later
 * on a 40 MB mesh.
 *
 * Assets get their own endpoints and object storage in M7. Until then the
 * honest answer is a refusal, not a column that silently accepts them.
 */
final class DocumentPolicy
{
    /**
     * 1 MiB of structured document. A project's geometry, layers and settings
     * fit comfortably; anything larger is either an asset or a serialised
     * mesh, and both belong in object storage.
     */
    public const MAX_DOCUMENT_BYTES = 1_048_576;

    /**
     * A single string this long is not a label, an id or a note — it is a
     * payload someone encoded.
     */
    public const MAX_STRING_BYTES = 65_536;

    /**
     * Deep enough for any document model, shallow enough that recursive
     * validation cannot be turned into a stack overflow by a crafted body.
     */
    public const MAX_DEPTH = 64;

    public function assertStorable(object $document): void
    {
        $encoded = json_encode($document);

        if ($encoded === false) {
            throw new UnprocessableEntityException(
                'INVALID_DOCUMENT',
                'The project document could not be encoded as JSON.',
            );
        }

        $size = strlen($encoded);

        if ($size > self::MAX_DOCUMENT_BYTES) {
            throw new PayloadTooLargeException(
                'The project document is larger than the platform stores inline.',
                ['limit_bytes' => self::MAX_DOCUMENT_BYTES, 'size_bytes' => $size],
            );
        }

        $this->walk(get_object_vars($document), '', 1);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function walk(array $values, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnprocessableEntityException(
                'DOCUMENT_TOO_DEEP',
                'The project document is nested more deeply than the platform stores.',
                ['path' => $path, 'limit' => self::MAX_DEPTH],
            );
        }

        foreach ($values as $key => $item) {
            $childPath = $path === '' ? (string) $key : $path . '/' . $key;

            // Objects and arrays are both containers here: the document is
            // decoded faithfully, so a JSON object stays an object and a JSON
            // array stays a list, and validation has to walk both.
            if (is_object($item)) {
                $this->walk(get_object_vars($item), $childPath, $depth + 1);

                continue;
            }

            if (is_array($item)) {
                $this->walk($item, $childPath, $depth + 1);

                continue;
            }

            if (is_string($item)) {
                $this->assertNotAnAsset($item, $childPath);
            }
        }
    }

    private function assertNotAnAsset(string $value, string $path): void
    {
        // A data: URI is an asset by construction, whatever its size.
        if (preg_match('/^\s*data:[^,]*,/i', $value) === 1) {
            throw new UnprocessableEntityException(
                'EMBEDDED_ASSET_REJECTED',
                'Project documents may not embed assets; upload them and reference them by id.',
                ['path' => $path, 'reason' => 'data URI'],
            );
        }

        $size = strlen($value);

        if ($size > self::MAX_STRING_BYTES) {
            throw new UnprocessableEntityException(
                'EMBEDDED_ASSET_REJECTED',
                'Project documents may not embed assets; upload them and reference them by id.',
                [
                    'path' => $path,
                    'reason' => 'string longer than the limit',
                    'limit_bytes' => self::MAX_STRING_BYTES,
                    'size_bytes' => $size,
                ],
            );
        }
    }
}
