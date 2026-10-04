<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/theme-templates — the platform's five starting points,
 * with their documents, in the order they are offered (2026-10-04).
 * `skin.manage`.
 */
final class ListThemeTemplatesController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        TenantThemeRoute::manageable($request);

        return new JsonResponse([
            'templates' => array_map(TenantThemePresenter::template(...), $this->themes->templates()),
        ], 200);
    }
}
