<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Controller\PalettePresenter;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/palettes/{name} — create a palette, or replace one's whole
 * document (2026-10-04): `{document: {…}}`, validated whole and refused whole.
 * Every organisation wearing it changes with it, at its next page load. The
 * platform administrator alone: `staff.design.manage`.
 */
final class SavePaletteController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        // Decoded to arrays once, here: the domain validates plain data and
        // never sees the transport's `stdClass`.
        $document = json_decode(json_encode(JsonBody::of($request)->requiredObject('document'), \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        $palette = $this->palettes->save($context->identity, StaffRoute::id($request, 'name'), is_array($document) ? $document : []);

        return new JsonResponse(['palette' => PalettePresenter::palette($palette)], 200);
    }
}
