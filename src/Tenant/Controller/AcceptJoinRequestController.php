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
 * POST /api/v1/tenants/current/members/{userId}/accept — let somebody in.
 *
 * The membership was written at sign-up, as a USER on every product the
 * organisation holds; this makes it live. Declining (`…/decline`) drops the
 * pending rows and keeps the account.
 */
final class AcceptJoinRequestController implements RouteHandler
{
    public function __construct(private readonly Joining $joining)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.manage');

        $userId = $request->getAttribute('userId');
        $this->joining->accept($context->tenantId, is_string($userId) ? $userId : '');

        return new EmptyResponse(204);
    }
}
