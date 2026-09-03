<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Context\StaffContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The guard on every staff route.
 *
 * It reads a {@see StaffContext} and never a RequestContext. That is not a
 * stylistic choice: a staff controller that could reach a RequestContext
 * would be a controller able to serve the same handler to a tenant member,
 * and the surfaces are separate precisely so that no `if (isStaff)` ever
 * decides which data somebody sees.
 */
final class StaffRoute
{
    public static function permitted(ServerRequestInterface $request, string $permission): StaffContext
    {
        $context = StaffContext::from($request);
        $context->requirePermission($permission);

        return $context;
    }

    public static function id(ServerRequestInterface $request, string $attribute): string
    {
        $value = $request->getAttribute($attribute);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function query(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return is_array($query) ? $query : [];
    }
}
