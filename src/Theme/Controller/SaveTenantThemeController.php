<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/tenant/themes/{name} — save a theme in the organisation, under
 * a name of its choosing, creating it or replacing it (2026-10-04):
 * `{document: {…}}`. Validated whole and refused whole. Saving the active
 * theme changes its members' screens at their next load. `skin.manage`.
 */
final class SaveTenantThemeController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantThemeRoute::manageable($request);

        $document = json_decode(json_encode(JsonBody::of($request)->requiredObject('document'), \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        $theme = $this->themes->save(
            $context->tenantId,
            $context->productId,
            TenantThemeRoute::name($request),
            is_array($document) ? $document : [],
            $context->userId,
        );

        return new JsonResponse(['theme' => TenantThemePresenter::full($theme)], 200);
    }
}
