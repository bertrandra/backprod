<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\TranslatableText;
use App\Staff\Domain\TranslationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/translations — everything the operator wrote, in every
 * language it has (2026-09-26).
 *
 * The console could already edit these one at a time: a feature's name on the
 * Features screen, an offer's on the Catalogue. What it could not do is answer
 * the question somebody asks when a language is half finished — *what is
 * missing in Italian?* — because the answer spans tables that have nothing
 * else in common.
 *
 * **This is the operator's words, not the application's.** Field labels,
 * buttons, hints and error wording live in the bundle's JSON catalogues, keyed
 * by the English and proved complete by `gate:i18n` (ADR-050,
 * translatable-fields-spec §1.5). They are not here and this endpoint is not a
 * reason to move them: the test that separates the two is whether the sentence
 * would be identical on every deployment of this platform.
 *
 * **A read and only a read.** Writing goes back through the operations that
 * own each row — `renameFeature`, `renameStaffOffer` — which already carry
 * their permission, their validation and their trail. A second way to write
 * the same rows is the drift the gates exist to prevent.
 *
 * `staff.catalog.manage`, which is the console's own catalogue permission:
 * offers are the larger half of what is written here, and a name a customer is
 * sold is exactly what that permission governs. Somebody holding it but not
 * `staff.features.manage` still reads the feature rows — a feature's name
 * appears in every tenant's catalogue, so there is nothing to withhold — and
 * the screen offers no edit they would be refused.
 */
final class ListTranslationsController implements RouteHandler
{
    public function __construct(private readonly TranslationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        return new JsonResponse([
            'texts' => array_map(
                static fn (TranslatableText $text): array => [
                    'kind' => $text->kind,
                    'id' => $text->id,
                    'code' => $text->code,
                    'field' => $text->field,
                    'source' => $text->source,
                    // The product an offer's catalogue belongs to, because the
                    // write that follows needs it and because an offer's code
                    // is unique only within one. Null for a feature, which is
                    // the platform's own vocabulary (ADR-052).
                    'product' => $text->product,
                    // Only what is written. A locale absent is a translation
                    // missing, and the screen counts those — so filling the
                    // gaps with empty strings here would make every sentence
                    // look translated into five languages.
                    'translations' => $text->translations,
                ],
                $this->desk->everything(),
            ),
        ], 200);
    }
}
