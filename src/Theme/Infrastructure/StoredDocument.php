<?php

declare(strict_types=1);

namespace App\Theme\Infrastructure;

use App\Shared\Exceptions\BadRequestException;
use App\Theme\Domain\ThemeDocument;

/**
 * A `jsonb` document, read back in the order it was written.
 *
 * `jsonb` keeps an object's keys in its own order (shortest first), so a
 * document read back raw would list `size` before `name`. Passed through its
 * own shape it reads as it was written — and a row this version no longer
 * reads as a theme is returned as stored, rather than made unreadable by the
 * code that is supposed to show it.
 */
final class StoredDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $document = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $document[(string) $key] = $value;
        }

        try {
            return ThemeDocument::fromArray($document)->toArray();
        } catch (BadRequestException) {
            return $document;
        }
    }
}
