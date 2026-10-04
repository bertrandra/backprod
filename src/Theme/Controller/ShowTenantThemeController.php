<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/themes/{name} — one of the organisation's themes, with
 * its document (2026-10-04). `THEME_NOT_FOUND` for a name this organisation
 * has not saved — another organisation's included, which is the same answer
 * on purpose. `skin.manage`.
 */
final class ShowTenantThemeController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantThemeRoute::manageable($request);
        $theme = $this->themes->show($context->tenantId, $context->productId, TenantThemeRoute::name($request));

        return new JsonResponse(['theme' => TenantThemePresenter::full($theme)], 200);
    }
}
