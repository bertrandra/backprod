<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Navigation\Service\NavigationDesk;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/navigation — the menu setup, every audience, as it is.
 *
 * Complete by construction: an audience nobody has set reads as
 * `{hidden: [], hide_empty: false}`, so the setup screen never has to tell
 * "unset" from "everything".
 */
final class ShowNavigationSetupController implements RouteHandler
{
    public function __construct(private readonly NavigationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::NAVIGATION_MANAGE);

        return new JsonResponse(['navigation' => $this->desk->show()->toArray()], 200);
    }
}
