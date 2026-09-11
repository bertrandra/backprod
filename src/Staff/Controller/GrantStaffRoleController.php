<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffAppointments;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/members — appoint somebody.
 *
 * Takes a user id rather than an email address. An email is what the console
 * shows and what a person recognises, but resolving one here would make this
 * endpoint a way to ask "does an account exist for this address?" from behind
 * a permission that is otherwise about staff, and answer it for any address
 * anybody cared to try. The console has `/admin/users` for finding somebody,
 * behind `admin.directory.read`, where looking up a person is the declared
 * purpose and is recorded as such.
 *
 * Returns the whole roster, not the one row: the screen's next state is the
 * list, and the caller should not have to guess it from a 201.
 */
final class GrantStaffRoleController implements RouteHandler
{
    public function __construct(private readonly StaffAppointments $appointments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::GRANT);
        $body = JsonBody::of($request);

        $this->appointments->grant(
            $context->identity,
            $body->requiredString('user_id', 64),
            $body->requiredString('role', 64),
        );

        $roster = $this->appointments->roster();

        return new JsonResponse([
            'members' => array_map(
                static fn (StaffMember $member): array => StaffPresenter::member($member),
                $roster['members'],
            ),
            'roles' => $roster['roles'],
        ], 201);
    }
}
