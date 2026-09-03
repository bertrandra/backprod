<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/me.
 *
 * Reads no tenant data, so it writes no access row: #21 is about crossing
 * the boundary, and telling somebody what they themselves hold crosses
 * nothing. It exists so "why was I refused?" is answerable without a support
 * round trip.
 */
final class StaffIdentityController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SELF_READ);

        return new JsonResponse(StaffPresenter::identity($context->identity), 200);
    }
}
