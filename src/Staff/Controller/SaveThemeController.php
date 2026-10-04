<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Service\ThemeLibrary;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/themes/{name} — save the design system under a name,
 * creating it or replacing what was there (2026-10-04): `{document: {…}}`,
 * the shape {@see \App\Theme\Domain\ThemeDocument} describes. Validated whole
 * and refused whole. `staff.design.manage`.
 */
final class SaveThemeController implements RouteHandler
{
    public function __construct(private readonly ThemeLibrary $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        // Decoded to arrays once, here: the domain validates plain data and
        // never sees the transport's `stdClass`.
        $document = json_decode(json_encode(JsonBody::of($request)->requiredObject('document'), \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        $theme = $this->themes->save(
            StaffRoute::id($request, 'name'),
            is_array($document) ? $document : [],
            $context->userId(),
        );

        return new JsonResponse(['theme' => ThemePresenter::full($theme)], 200);
    }
}
