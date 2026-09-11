<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Context\StaffContext;
use App\Shared\Exceptions\BadRequestException;
use App\Staff\Domain\AccessMotive;
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

    /**
     * The motive for a read that crosses into a tenant's own data (R14).
     *
     * **Headers, not a body.** These are GETs, and a GET with a body is a
     * request half the intermediaries between here and the caller will drop.
     * Headers also mean the motive travels with the request rather than being a
     * separate call somebody could forget to make — non-negotiable #21 wants the
     * reason recorded *with* the access, not beside it.
     *
     * The header names carry `X-Access-` rather than `X-Reason`, so that reading
     * a request in a proxy log makes the pair obvious.
     */
    public static function motive(ServerRequestInterface $request): AccessMotive
    {
        return AccessMotive::from(
            $request->getHeaderLine('X-Access-Purpose'),
            $request->getHeaderLine('X-Access-Reason'),
        );
    }

    /**
     * The product a storefront route is about.
     *
     * A query parameter rather than `X-Product`: a staff route resolves no
     * product of its own — a platform role grants no membership, and §12.1's
     * ambient product comes from one — so the product is named explicitly and
     * the console says which one it is showing.
     *
     * Absent is refused rather than defaulted. A console that silently
     * administered whichever product came first would eventually advertise
     * the wrong one.
     */
    public static function productCode(ServerRequestInterface $request): string
    {
        $code = self::query($request)['product'] ?? null;

        if (!is_string($code) || trim($code) === '' || mb_strlen($code) > 64) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => 'product', 'requirement' => 'must name a product'],
            );
        }

        return trim($code);
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
        // No guard: PSR-7 types this as an array already, and re-checking it
        // is a condition that can never be false.
        return $request->getQueryParams();
    }
}
