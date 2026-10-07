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
     * A single string this long is not a label, an id or a note — it is a
     * payload someone encoded.
     */
    public const MAX_STRING_BYTES = 65_536;

    /**
     * Deep enough for any document model, shallow enough that recursive
     * validation cannot be turned into a stack overflow by a crafted body.
     */
    public const MAX_DEPTH = 64;

    public function __construct(private readonly DocumentLimit $limit)
    {
    }

    /**
     * How large a document is, measured the way the limit measures it.
     *
     * One measure, in one place: a screen showing "3.9 MB" beside a project
     * is only useful if it is the number the limit will be compared against
     * on the next save. PostgreSQL's `octet_length(document::text)`
     * would answer something else — JSONB prints a space after every colon
     * and comma — and two sizes for one document is one too many.
     *
     * Null where the document cannot be encoded, which a stored one always
     * can: it was encoded to be stored.
     */
    public static function sizeOf(object $document): ?int
    {
        $encoded = json_encode($document);

        return $encoded === false ? null : strlen($encoded);
    }

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

        // Read on every save rather than held: the operator sets it in the
        // console (2026-10-07), and a change applies from the next save on.
        // A larger limit admits what was refused; a smaller one refuses no
        // document already stored until somebody saves it again.
        $limit = $this->limit->maxDocumentMib() * DocumentLimit::BYTES_PER_MIB;

        if ($size > $limit) {
            throw new PayloadTooLargeException(
                'The project document is larger than the platform stores inline.',
                ['limit_bytes' => $limit, 'size_bytes' => $size],
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
