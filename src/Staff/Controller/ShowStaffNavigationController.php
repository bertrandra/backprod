<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Navigation\Service\MenuResolver;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/me/navigation — what the console leaves out for this
 * person: the platform administrator's setup, resolved.
 *
 * Any platform role may ask, as any may ask `/staff/me`: the answer is a
 * list of entry ids to hide, and the shell hides them beside what the
 * person's permissions already hide. Courtesy, not control — every staff
 * route checks its own permission regardless.
 */
final class ShowStaffNavigationController implements RouteHandler
{
    public function __construct(private readonly MenuResolver $menus)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::SELF_READ);

        return new JsonResponse(['hidden' => $this->menus->forStaff()], 200);
    }
}
