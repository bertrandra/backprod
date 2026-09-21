<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\ProductScope;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ProductDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/products/{productId}/credentials — a key for the
 * product's server (ADR-051 §4). The answer carries the bearer in the clear,
 * once; the platform stores its hash and cannot say it again. A label says
 * which deployment holds it, the scopes say what it may do, and it expires
 * in a year unless told otherwise — the console says which keys have thirty
 * days left. `staff.products.manage`, and trailed as ISSUE_PRODUCT_KEY.
 */
final class IssueProductCredentialController implements RouteHandler
{
    private const DEFAULT_LIFETIME_DAYS = 365;

    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);
        $body = JsonBody::of($request);

        $scopes = array_values(array_unique($body->requiredStringList('scopes')));

        if ($scopes === []) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'scopes', 'requirement' => 'at least one of ' . implode(', ', ProductScope::ALL)]);
        }

        foreach ($scopes as $scope) {
            if (!ProductScope::isKnown($scope)) {
                throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'scopes', 'requirement' => 'each one of ' . implode(', ', ProductScope::ALL)]);
            }
        }

        $days = $body->has('expires_in_days') ? $body->requiredInt('expires_in_days', 1) : self::DEFAULT_LIFETIME_DAYS;

        if ($days > 3650) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'expires_in_days', 'requirement' => 'at most 3650']);
        }

        $issued = $this->desk->issueCredential(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            $body->requiredString('label', 100),
            $scopes,
            $days,
        );

        return new JsonResponse([
            'credential' => StaffPresenter::credential($issued->key),
            'bearer' => $issued->bearer,
        ], 201);
    }
}
