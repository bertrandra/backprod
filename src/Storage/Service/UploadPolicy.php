<?php

declare(strict_types=1);

namespace App\Storage\Service;

use App\Shared\Exceptions\PayloadTooLargeException;
use App\Shared\Exceptions\UnprocessableEntityException;
use finfo;

/**
 * What may be uploaded, decided from the bytes rather than from the request.
 *
 * The rule this class exists for: **a client's Content-Type is a claim, and a
 * claim is what an attacker controls.** A PHP script announcing itself as
 * `image/png` is the oldest upload bug there is, and the only defence that
 * works is to look at the bytes and believe those instead.
 *
 * So the sniffed type is what is checked against the allowlist, what is
 * stored, and what is served back later. The claimed type is never consulted
 * for anything.
 */
final class UploadPolicy
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * What the platform will store.
     *
     * An allowlist, not a denylist: a denylist is a bet that you thought of
     * every dangerous type, and `.phtml` is the reminder that nobody does.
     *
     * SVG is deliberately absent. It is an image to a user and a script
     * container to a browser, and serving one from the platform's own origin
     * would be a stored cross-site scripting hole with a friendly extension.
     */
    private const ALLOWED = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/json',
        'application/zip',
        'text/csv',
        'text/plain',
    ];

    /**
     * @return array{content_type: string, byte_size: int, checksum: string}
     */
    public function inspect(string $contents): array
    {
        $size = strlen($contents);

        if ($size === 0) {
            throw new UnprocessableEntityException(
                'UPLOAD_EMPTY',
                'There is nothing to upload.',
            );
        }

        if ($size > self::MAX_BYTES) {
            throw new PayloadTooLargeException(
                'That file is larger than the limit.',
                ['limit_bytes' => self::MAX_BYTES, 'size_bytes' => $size],
            );
        }

        $sniffed = $this->sniff($contents);

        if (!in_array($sniffed, self::ALLOWED, true)) {
            throw new UnprocessableEntityException(
                'UPLOAD_TYPE_REJECTED',
                'That kind of file cannot be stored.',
                // The sniffed type is disclosed because the uploader supplied
                // the bytes it came from — it tells them nothing they did not
                // already have, and without it "rejected" is unactionable.
                ['detected' => $sniffed, 'allowed' => self::ALLOWED],
            );
        }

        return [
            'content_type' => $sniffed,
            'byte_size' => $size,
            'checksum' => hash('sha256', $contents),
        ];
    }

    /**
     * A filename fit to hand back, and never to address anything with.
     *
     * Directory separators and control characters go, and the result is
     * capped. This is a display label — the object is addressed by a
     * generated key — so the only requirement is that it cannot escape a
     * Content-Disposition header or a path somebody later builds carelessly.
     */
    public function safeFilename(string $filename): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', $filename) ?? '';
        $trimmed = trim($stripped, " \t.");

        return $trimmed === '' ? 'download' : mb_substr($trimmed, 0, 120);
    }

    private function sniff(string $contents): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        if (!is_string($detected) || $detected === '') {
            throw new UnprocessableEntityException(
                'UPLOAD_TYPE_UNKNOWN',
                'The contents of that file could not be identified.',
            );
        }

        // Whatever it says, unmodified. Checked against the allowlist by the
        // caller, so an unrecognised or merely odd file lands on
        // application/octet-stream and is refused — which is the safe
        // direction for a type nobody could identify.
        return $detected;
    }
}
