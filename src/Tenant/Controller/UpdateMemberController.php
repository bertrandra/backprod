<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\MemberAdministration;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/tenants/current/members/{userId} — set a member's roles.
 *
 * The submitted list replaces what the member had, so omitting a role removes
 * it. Merging instead would make it impossible to take a role away.
 */
final class UpdateMemberController implements RouteHandler
{
    public function __construct(private readonly MemberAdministration $members)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.manage');

        $userId = $request->getAttribute('userId');

        $member = $this->members->replaceRoles(
            $context->tenantId,
            $context->productId,
            is_string($userId) ? $userId : '',
            JsonBody::of($request)->requiredStringList('roles'),
        );

        return new JsonResponse(MemberPresenter::one($member), 200);
    }
}
