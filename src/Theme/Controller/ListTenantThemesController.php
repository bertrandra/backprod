<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/themes — the organisation's own themes, by name, each
 * saying whether it is the active one (2026-10-04). `skin.manage`.
 */
final class ListTenantThemesController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantThemeRoute::manageable($request);

        return new JsonResponse([
            'themes' => array_map(TenantThemePresenter::summary(...), $this->themes->all($context->tenantId, $context->productId)),
        ], 200);
    }
}
