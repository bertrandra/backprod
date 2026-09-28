<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Auth\Domain\SignUpSettings;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/sign-up/settings — whether a new account must prove its
 * address before it may use the platform (ADR-063). `staff.sign_up.manage`.
 */
final class ShowSignUpSettingsController implements RouteHandler
{
    public function __construct(private readonly SignUpSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::SIGN_UP_MANAGE);

        return new JsonResponse(['confirm_email' => $this->settings->emailConfirmationRequired()], 200);
    }
}
