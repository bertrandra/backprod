<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Controller\PalettePresenter;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/palette — choose
 * the palette an organisation wears in a product it holds (2026-10-04):
 * `{palette: "…"}`, or `{palette: null}` for the platform's design. The same
 * row the organisation's administrator changes on their own screen, so the
 * last of the two to choose is what both see.
 * `staff.design.manage`.
 */
final class AssignPaletteController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);
        $body = JsonBody::of($request);

        if (!$body->has('palette')) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'palette', 'requirement' => 'a palette name, or null for none']);
        }

        $palette = $this->palettes->assign(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
            $body->optionalNullableString('palette', 63),
        );

        return new JsonResponse(['palette' => $palette === null ? null : PalettePresenter::palette($palette)], 200);
    }
}
