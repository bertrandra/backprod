<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/tenants/current/members/{userId}/decline — turn somebody away.
 *
 * Only a pending membership can be declined; a live member leaves through
 * the ordinary removal, which has the last-administrator rule the pending
 * rows never need. The account stays: the person may ask again, or join
 * another organisation.
 */
final class DeclineJoinRequestController implements RouteHandler
{
    public function __construct(private readonly Joining $joining)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.manage');

        $userId = $request->getAttribute('userId');
        $this->joining->decline($context->tenantId, is_string($userId) ? $userId : '');

        return new EmptyResponse(204);
    }
}
