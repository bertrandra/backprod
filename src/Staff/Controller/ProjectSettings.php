<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Project\Domain\DocumentLimit;

/**
 * One shape for the project settings, read and written.
 *
 * It carries the bounds the server enforces so the screen offers exactly
 * those, and the host's own upload ceiling, because PHP refuses a request
 * body above `post_max_size` before this platform ever sees it — and that
 * refusal is not this platform's `PAYLOAD_TOO_LARGE`, it is an empty body.
 * A limit set above it would be a number the host quietly overrules, so the
 * screen says so rather than letting the operator find out from a customer.
 */
final class ProjectSettings
{
    /**
     * @return array{
     *     max_document_mib: int, default_mib: int, minimum_mib: int, maximum_mib: int,
     *     host_upload_bytes: int|null, host_overrules: bool
     * }
     */
    public static function of(DocumentLimit $limit): array
    {
        $mib = $limit->maxDocumentMib();
        $host = self::hostUploadBytes();

        return [
            'max_document_mib' => $mib,
            'default_mib' => DocumentLimit::DEFAULT_MIB,
            'minimum_mib' => DocumentLimit::MINIMUM_MIB,
            'maximum_mib' => DocumentLimit::MAXIMUM_MIB,
            'host_upload_bytes' => $host,
            // Decided here, not on the screen: comparing two sizes is the
            // server's to do, and the screen only says what the answer was.
            // The body carries the document and a little more, so a host
            // ceiling equal to the limit already overrules it.
            'host_overrules' => $host !== null && $host <= $mib * DocumentLimit::BYTES_PER_MIB,
        ];
    }

    /**
     * `post_max_size` in bytes, or null where the host sets none (0).
     *
     * PHP writes it with an optional K, M or G suffix, binary multiples.
     */
    private static function hostUploadBytes(): ?int
    {
        $raw = trim((string) ini_get('post_max_size'));

        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $raw, $match) !== 1) {
            return null;
        }

        $bytes = (int) $match[1] * match (strtoupper($match[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return $bytes === 0 ? null : $bytes;
    }
}
