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
 * POST /api/v1/tenants/current/members — add an existing platform user.
 */
final class AddMemberController implements RouteHandler
{
    public function __construct(private readonly MemberAdministration $members)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('members.manage');

        $body = JsonBody::of($request);

        $member = $this->members->add(
            $context->tenantId,
            $context->productId,
            $body->requiredString('email', 320),
            $body->requiredStringList('roles'),
        );

        return new JsonResponse(MemberPresenter::one($member), 201);
    }
}
