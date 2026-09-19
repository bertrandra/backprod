<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Notification\Service\MailTester;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/mail/test — a test mail, to the caller's own address,
 * rendered from a template with a sample payload (2026-09-19). Sent inside
 * the request, so the answer is the mail host's, now. `staff.mail.manage`.
 */
final class SendTestMailController implements RouteHandler
{
    public function __construct(private readonly MailTester $tester)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::MAIL_MANAGE);

        $type = JsonBody::of($request)->requiredString('type', 64);

        return new JsonResponse($this->tester->send($type, $context->identity->email ?? ''), 200);
    }
}
