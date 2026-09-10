<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use App\Shared\Exceptions\BadRequestException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The door to `/tenant/skin`.
 *
 * Reading and writing are gated differently, and the difference is the point
 * of having both mechanisms. **Reading needs nothing but membership**: a
 * client has to know how to render itself before it knows what the tenant
 * bought, and a tenant with no skin gets the same answer either way.
 *
 * **Writing needs two things that do not imply each other** — the
 * `skin.manage` permission, which says this person may configure the tenant,
 * and the `white_label` entitlement (§12.1), which says the tenant's plan
 * includes the feature at all. A TENANT_ADMIN on a plan without white label
 * is refused, and so is an ordinary member on a plan with it.
 */
final class SkinRoute
{
    public const CAPABILITY = 'white_label';

    /** Six hex digits cannot express `red;} body{display:none`. */
    private const HEX = '/^#[0-9a-f]{6}$/';

    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return RequestContextReader::from($request);
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('skin.manage');
        $context->requireCapability(self::CAPABILITY);

        return $context;
    }

    /**
     * A colour, normalised to lower case, or null to clear it.
     *
     * The database refuses anything else too, and would refuse it as
     * `tenant_skins_primary_is_hex`. Refusing here names the field the caller
     * has to look at, and does it before the row is touched.
     */
    public static function colour(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || preg_match(self::HEX, strtolower($value)) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => $field, 'requirement' => 'must be a colour like "#1f4b99", or null to clear it'],
            );
        }

        return strtolower($value);
    }
}
