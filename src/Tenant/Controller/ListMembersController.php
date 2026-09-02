<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\MemberAdministration;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenants/current/members.
 */
final class ListMembersController implements RouteHandler
{
    public function __construct(private readonly MemberAdministration $members)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.read');

        return new JsonResponse([
            'members' => MemberPresenter::many(
                $this->members->list($context->tenantId, $context->productId),
            ),
        ], 200);
    }
}
