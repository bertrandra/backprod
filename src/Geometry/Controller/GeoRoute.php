<?php

declare(strict_types=1);

namespace App\Geometry\Controller;

use App\Geometry\Domain\CoordinateReference;
use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The door to `/geometry` (§10.2).
 *
 * Unlike every other module's route helper, this one asks for a **capability**
 * rather than a permission, and it is the first place in the platform that
 * does. The distinction is §13's: a permission is what a member's role lets
 * them do with the tenant's data, and a capability is what the tenant has
 * bought. Geometry touches no tenant data, so there is no role question to
 * ask; what there is, is a plan that either includes GIS or does not — which
 * is exactly what `gis.access` names in the §10.2 responsibility matrix.
 */
final class GeoRoute
{
    public const CAPABILITY = 'gis.access';

    public static function computable(ServerRequestInterface $request): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requireCapability(self::CAPABILITY);

        return $context;
    }

    /**
     * The coordinate reference, which the caller must state.
     *
     * @see CoordinateReference for why this is not inferred
     */
    public static function reference(JsonBody $body): CoordinateReference
    {
        $value = $body->requiredString('crs', 32);
        $reference = CoordinateReference::tryFrom($value);

        if ($reference === null) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                [
                    'field' => 'crs',
                    'requirement' => 'must be one of ' . implode(', ', CoordinateReference::codes()),
                ],
            );
        }

        return $reference;
    }
}
