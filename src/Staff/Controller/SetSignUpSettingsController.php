<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Auth\Domain\SignUpSettings;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/sign-up/settings — ask new accounts to prove their
 * address, or stop asking (ADR-063). Platform-wide, `staff.sign_up.manage`.
 *
 * Switching it **on** binds the sign-ups that follow and not the ones
 * before: the deadline is written at sign-up, and a deadline nobody was
 * given is not one to enforce (ADR-061). Switching it **off** releases
 * everybody at once, including those already refused — which is the whole
 * reason the refusal asks this setting rather than the column alone.
 */
final class SetSignUpSettingsController implements RouteHandler
{
    public function __construct(private readonly SignUpSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::SIGN_UP_MANAGE);

        $this->settings->requireEmailConfirmation(JsonBody::of($request)->requiredBool('confirm_email'));

        return new JsonResponse(['confirm_email' => $this->settings->emailConfirmationRequired()], 200);
    }
}
