<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/tenant/palette — choose which palette this organisation's
 * screens wear in this product: `{palette: "forest-ledger"}`, or
 * `{palette: null}` for the platform's own design (2026-10-04). The same row
 * the console's matrix writes. `palette` must be present: a body that forgot
 * it is not a request to go back to the platform's design. `skin.manage`.
 */
final class SelectTenantPaletteController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantPaletteRoute::manageable($request);
        $body = JsonBody::of($request);

        if (!$body->has('palette')) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'palette', 'requirement' => 'a palette name, or null for none']);
        }

        $palette = $this->palettes->select($context->tenantId, $context->productId, $body->optionalNullableString('palette', 63), $context->userId);

        return new JsonResponse(['palette' => $palette === null ? null : PalettePresenter::palette($palette)], 200);
    }
}
