<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The door to `/tenant/themes` (2026-10-04).
 *
 * **Reading the active theme needs nothing but membership**, for the reason
 * the skin gives: a screen has to know how to paint itself before it knows
 * what anybody may do, and every member's screen wears it.
 *
 * **Everything else needs `skin.manage`** — the templates, the organisation's
 * copies, saving and choosing one. The permission alone, and not the
 * `white_label` capability the skin's colours also ask for: the operator
 * chose (2026-10-04) that any organisation's administrator may theme its
 * screens, whatever its offer sells.
 */
final class TenantThemeRoute
{
    public const PERMISSION = 'skin.manage';

    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return RequestContextReader::from($request);
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission(self::PERMISSION);

        return $context;
    }

    public static function name(ServerRequestInterface $request): string
    {
        $name = $request->getAttribute('name');

        return is_string($name) ? $name : '';
    }
}
