<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;

/**
 * The one rule every catalogue code follows, in one place.
 *
 * A plan code and a feature code are identifiers: they key `role_permissions`
 * lookups, appear in exports, and are what a future integration will address
 * them by. They are validated on the way in rather than merely trimmed, for
 * the same reason a product code is — and lowercased, because capitals are a
 * typing habit and not a different plan.
 */
final class CatalogueRoute
{
    public static function code(JsonBody $body, string $field = 'code'): string
    {
        $code = strtolower($body->requiredString($field, 64));

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                [
                    'field' => $field,
                    'requirement' => 'must be lowercase letters, digits, hyphens or underscores, starting with a letter or digit',
                ],
            );
        }

        return $code;
    }
}
