<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Shared\Context\StaffContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The guard on every admin route.
 *
 * It reads a {@see StaffContext} and never a RequestContext, for the reason
 * `StaffRoute` gives and which applies here with more force: an admin
 * controller able to reach a RequestContext would be one that could serve
 * platform-wide financial data to a tenant member. Non-negotiable #19 is
 * that admin reporting is separated from what a tenant sees, and separated
 * means no handler can be reached both ways — not that a handler checks
 * which way it was reached.
 *
 * Its own helper rather than Staff's, as Tax has its own: three methods
 * duplicated is cheaper than a dependency between two surfaces that exist to
 * be independent.
 */
final class AdminRoute
{
    public static function permitted(ServerRequestInterface $request, string $permission): StaffContext
    {
        $context = StaffContext::from($request);
        $context->requirePermission($permission);

        return $context;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function query(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    /**
     * A query parameter that is a non-empty string, or null.
     *
     * @param array<array-key, mixed> $query
     */
    public static function filter(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
