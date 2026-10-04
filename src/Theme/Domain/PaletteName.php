<?php

declare(strict_types=1);

namespace App\Theme\Domain;

use App\Shared\Exceptions\BadRequestException;

/**
 * What a palette is called (2026-10-04): lower-case words joined by hyphens,
 * because it travels in a path — `/staff/palettes/forest-ledger`.
 */
final class PaletteName
{
    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $value) !== 1) {
            throw new BadRequestException('VALIDATION_FAILED', 'The palette name is not valid.', [
                'field' => 'name',
                'requirement' => 'lower-case letters, digits and hyphens, 1 to 63 characters, not starting with a hyphen',
            ]);
        }

        return new self($value);
    }
}
