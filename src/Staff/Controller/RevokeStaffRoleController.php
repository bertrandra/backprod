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
 * DELETE /api/v1/staff/members/{userId}/roles/{role} — remove somebody.
 *
 * Two refusals live behind this and they are not the same: removing your own
 * administrator role is refused by {@see StaffAppointments}, and removing the
 * platform's last one is refused by the database. Both answer 409 and each
 * says what to do instead, because "ask a colleague" and "appoint somebody
 * first" are different remedies and a lone administrator given the wrong one
 * would follow advice that cannot work.
 */
final class RevokeStaffRoleController implements RouteHandler
{
    public function __construct(private readonly StaffAppointments $appointments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::GRANT);

        $this->appointments->revoke(
            $context->identity,
            StaffRoute::id($request, 'userId'),
            StaffRoute::id($request, 'role'),
        );

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
