<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Notification\Service\MailTester;
use App\Notification\Service\MailWording;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Validation\Locale;
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
        $locale = $body->has('locale') ? $body->requiredString('locale', 8) : Locale::DEFAULT;

        if (!Locale::isKnown($locale)) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'locale', 'requirement' => 'one of ' . implode(', ', Locale::ALL)]);
        }

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

        $this->wording->save($templates, $locale);

        return new JsonResponse([
            'locale' => $locale,
            'locales' => Locale::ALL,
            'templates' => $this->wording->catalogue($locale),
            'live' => $this->tester->isLive(),
        ], 200);
    }
}
