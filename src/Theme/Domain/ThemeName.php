<?php

declare(strict_types=1);

namespace App\Theme\Domain;

use App\Shared\Exceptions\BadRequestException;

/**
 * What a stored theme is called (2026-10-04): lower-case words joined by
 * hyphens, because it travels in a path — `/staff/themes/default`.
 *
 * `default` is the name the console saves under unless told otherwise. It is
 * a name like any other, not a row the migration seeds: a seeded document
 * would be a copy of the stylesheet as it stood on the day the migration was
 * written, and migrations are history (ADR-016) while the stylesheet moves.
 */
final class ThemeName
{
    public const DEFAULT = 'default';

    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $value) !== 1) {
            throw new BadRequestException('VALIDATION_FAILED', 'The theme name is not valid.', [
                'field' => 'name',
                'requirement' => 'lower-case letters, digits and hyphens, 1 to 63 characters, not starting with a hyphen',
            ]);
        }

        return new self($value);
    }
}
