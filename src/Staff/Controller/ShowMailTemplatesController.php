<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Notification\Service\MailTester;
use App\Notification\Service\MailWording;
use App\Shared\Http\RouteHandler;
use App\Shared\Validation\Locale;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/mail/templates — the words the platform's mails say:
 * each editable type with its default, what stands today and its
 * placeholders (2026-09-19). `staff.mail.manage`.
 */
final class ShowMailTemplatesController implements RouteHandler
{
    public function __construct(
        private readonly MailWording $wording,
        private readonly MailTester $tester,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::MAIL_MANAGE);

        // `?locale=fr` reads one language's words (ADR-050); English is the default.
        $asked = $request->getQueryParams()['locale'] ?? null;
        $locale = Locale::of(is_string($asked) ? $asked : null);

        return new JsonResponse([
            'locale' => $locale,
            'locales' => Locale::ALL,
            'templates' => $this->wording->catalogue($locale),
            'live' => $this->tester->isLive(),
        ], 200);
    }
}
