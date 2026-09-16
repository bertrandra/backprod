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
 *
 * Wrapped in `staff`, as the contract has always said. The presenter used to
 * answer the identity at the top level while `openapi.json`, the generated
 * client and every stub described it wrapped — and the console's own gates
 * read `data.staff`, so against a real deployment `useStaffIdentity` failed
 * on every screen that asked. The drift CLAUDE.md warns of, found by a
 * button that never appeared.
 */
final class StaffIdentityController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SELF_READ);

        return new JsonResponse(['staff' => StaffPresenter::identity($context->identity)], 200);
    }
}
