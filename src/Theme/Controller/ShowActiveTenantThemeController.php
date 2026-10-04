<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/theme — the theme this organisation's screens wear, or
 * `null` for the platform's own design (2026-10-04). Any member: the shell
 * reads it to paint itself.
 */
final class ShowActiveTenantThemeController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantThemeRoute::readable($request);
        $theme = $this->themes->active($context->tenantId, $context->productId);

        return new JsonResponse(['theme' => $theme === null ? null : TenantThemePresenter::full($theme)], 200);
    }
}
