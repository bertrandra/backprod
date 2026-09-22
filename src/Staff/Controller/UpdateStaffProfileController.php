<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Identity\Service\Profile;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\StaffRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/me — a platform staff member's own name and language
 * (2026-09-22).
 *
 * `PATCH /me` is a tenant route: it resolves a product and a membership,
 * and platform staff with no membership are refused there — which left the
 * platform administrator the one person on the platform with no way to
 * their own profile. The account menu offered *Your profile* to whoever
 * `/me` knew, and knew nobody in the console.
 *
 * Two fields, the person's own: the display name and the language they
 * read in. Not the default product — that is a choice among memberships,
 * and staff without one have nothing to choose from; somebody who is both
 * has `/profile` for it. The authority is being that person, which
 * `staff.self.read` already says: it is the permission every platform role
 * holds so that "who am I here?" is always answerable, and a person's own
 * name needs no more than that.
 *
 * The same `Profile` service writes it as `/me` does — one place the rules
 * about a name and a language live, whichever door they came through.
 */
final class UpdateStaffProfileController implements RouteHandler
{
    public function __construct(
        private readonly Profile $profile,
        private readonly StaffRepository $staff,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SELF_READ);
        $body = JsonBody::of($request);

        // PATCH is partial: an absent field is untouched, an explicit null
        // clears the name. Those are different requests.
        if ($body->has('display_name')) {
            $this->profile->rename($context->identity->userId, $body->optionalNullableString('display_name', 120));
        }

        if ($body->has('locale')) {
            $this->profile->chooseLocale($context->identity->userId, $body->requiredString('locale', 8));
        }

        // Read back through the same port the identity route answers from,
        // so what this returns is exactly what the next `GET /staff/me` says.
        $identity = $this->staff->find($context->identity->userId) ?? $context->identity;

        return new JsonResponse(['staff' => StaffPresenter::identity($identity)], 200);
    }
}
