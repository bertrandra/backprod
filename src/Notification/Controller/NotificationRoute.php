<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The checks every notification endpoint starts from.
 *
 * These permissions govern a person's *own* inbox rather than anything
 * administrative, which is why every role has them: a tenant member who could
 * not switch off an email would have no way to stop it. What keeps one
 * person's notifications away from another is not the permission but the
 * scoping — every query is by `userId` from the resolved context, never by an
 * id from the request.
 */
final class NotificationRoute
{
    private const MAX_PAGE = 100;

    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'notifications.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'notifications.manage');
    }

    public static function notificationId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('notificationId');

        return is_string($value) ? $value : '';
    }

    public static function consentId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('consentId');

        return is_string($value) ? $value : '';
    }

    public static function unreadOnly(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['unread'] ?? null) === 'true';
    }

    /**
     * @return array{int, int}
     */
    public static function page(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 25;
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
