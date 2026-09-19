<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Notification\Service\MailTester;
use App\Notification\Service\MailWording;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/mail/templates — the whole set of words at once
 * (2026-09-19): `{templates: {type: {subject, body}}}`. A type left out, or
 * given empty, goes back to its default; an unknown type is ignored.
 * `staff.mail.manage`.
 */
final class SetMailTemplatesController implements RouteHandler
{
    public function __construct(
        private readonly MailWording $wording,
        private readonly MailTester $tester,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::MAIL_MANAGE);

        $body = JsonBody::of($request);
        $raw = $body->requiredObject('templates');

        /** @var array<string, array{subject: string, body: string}> $templates */
        $templates = [];

        foreach (get_object_vars($raw) as $type => $template) {
            if (!is_string($type) || !$template instanceof \stdClass) {
                continue;
            }

            $subject = $template->subject ?? '';
            $text = $template->body ?? '';

            if (!is_string($subject) || !is_string($text) || mb_strlen($subject) > 200 || mb_strlen($text) > 10_000) {
                throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => $type, 'requirement' => 'subject up to 200 characters, body up to 10000']);
            }

            $templates[$type] = ['subject' => $subject, 'body' => $text];
        }

        $this->wording->save($templates);

        return new JsonResponse(['templates' => $this->wording->catalogue(), 'live' => $this->tester->isLive()], 200);
    }
}
