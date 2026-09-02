<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\MemberAdministration;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/tenants/current/members/{userId}.
 *
 * Removes membership of this tenant and product only. The platform user
 * remains, because they may belong to other tenants — and because deleting a
 * person on a member-removal endpoint would be a surprising amount of damage.
 */
final class RemoveMemberController implements RouteHandler
{
    public function __construct(private readonly MemberAdministration $members)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.manage');

        $userId = $request->getAttribute('userId');

        $this->members->remove(
            $context->tenantId,
            $context->productId,
            is_string($userId) ? $userId : '',
        );

        return new EmptyResponse(204);
    }
}
