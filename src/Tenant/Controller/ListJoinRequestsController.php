<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenants/current/members/requests — who asked to join and is
 * waiting (2026-09-17). Read with `members.read`, like the members
 * themselves; deciding takes `members.manage`.
 */
final class ListJoinRequestsController implements RouteHandler
{
    public function __construct(private readonly Joining $joining)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.read');

        return new JsonResponse(['requests' => MemberPresenter::many($this->joining->requests($context->tenantId))], 200);
    }
}
