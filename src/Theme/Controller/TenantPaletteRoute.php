<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The organisation's door to its palette (2026-10-04).
 *
 * **Reading what the screens wear needs nothing but membership**, for the
 * reason the skin gives: a screen has to know how to paint itself before it
 * knows what anybody may do there.
 *
 * **Choosing needs `skin.manage`**, and only that — the operator chose not to
 * tie it to the offer. Choosing is all an organisation does: the palettes
 * themselves are the platform administrator's.
 */
final class TenantPaletteRoute
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
}
