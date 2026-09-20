<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Notification\Service\MailTester;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Validation\Locale;
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

        $body = JsonBody::of($request);
        $type = $body->requiredString('type', 64);
        $locale = $body->has('locale') ? $body->requiredString('locale', 8) : Locale::DEFAULT;

        // One of the languages the platform speaks, refused otherwise — the
        // same answer the save endpoint gives, so the two cannot drift.
        if (!Locale::isKnown($locale)) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'locale', 'requirement' => 'one of ' . implode(', ', Locale::ALL)]);
        }

        return new JsonResponse($this->tester->send($type, $context->identity->email ?? '', $locale), 200);
    }
}
