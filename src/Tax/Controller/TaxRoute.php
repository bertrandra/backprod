<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The checks every fiscal endpoint starts from.
 *
 * Gathered here rather than repeated across seven controllers, because the
 * one that forgets is the one that shows another company's fiscal history.
 *
 * Reading is `tax.read`; changing the profile and closing a period are
 * `tax.manage` — closure especially, since it is one-way and audited (§25.2).
 */
final class TaxRoute
{
    private const MAX_PAGE = 200;

    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'tax.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'tax.manage');
    }

    public static function periodId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('periodId');

        return is_string($value) ? $value : '';
    }

    /**
     * A query parameter, as a string, or null when it is absent or not one.
     */
    public static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A date from the query string, or null.
     *
     * An unparseable date becomes null rather than an error: a filter that
     * cannot be understood is better treated as absent than as a reason to
     * refuse the whole listing.
     */
    public static function date(ServerRequestInterface $request, string $name): ?DateTimeImmutable
    {
        $value = self::query($request, $name);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{int, int}
     */
    public static function page(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50;
        $offset = isset($params['offset']) && is_numeric($params['offset']) ? (int) $params['offset'] : 0;

        return [max(1, min($limit, self::MAX_PAGE)), max(0, $offset)];
    }

    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission($permission);

        return $context;
    }
}
