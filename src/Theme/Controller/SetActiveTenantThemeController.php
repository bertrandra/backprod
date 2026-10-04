<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Theme\Service\TenantThemes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/tenant/theme — choose which of the organisation's themes its
 * members' screens wear: `{name: "…"}`, or `{name: null}` to go back to the
 * platform's own design (2026-10-04). `name` must be present: a body that
 * forgot it is not a request to switch theming off. `skin.manage`.
 */
final class SetActiveTenantThemeController implements RouteHandler
{
    public function __construct(private readonly TenantThemes $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantThemeRoute::manageable($request);
        $body = JsonBody::of($request);

        if (!$body->has('name')) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'name', 'requirement' => 'a theme name, or null for none']);
        }

        $theme = $this->themes->activate($context->tenantId, $context->productId, $body->optionalNullableString('name', 63));

        return new JsonResponse(['theme' => $theme === null ? null : TenantThemePresenter::full($theme)], 200);
    }
}
