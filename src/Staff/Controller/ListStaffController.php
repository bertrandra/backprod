<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffAppointments;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/members.
 *
 * Unpaged, and that is a statement about what this list is: platform staff is
 * a handful of people, and a roster that needed paging would mean something
 * had gone wrong with who holds platform authority. The grantable roles ride
 * along in the same response so the screen offers what the database has
 * rather than a list compiled into the bundle.
 */
final class ListStaffController implements RouteHandler
{
    public function __construct(private readonly StaffAppointments $appointments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::GRANT);

        $roster = $this->appointments->roster();

        return new JsonResponse([
            'members' => array_map(
                static fn (StaffMember $member): array => StaffPresenter::member($member),
                $roster['members'],
            ),
            'roles' => $roster['roles'],
        ], 200);
    }
}
