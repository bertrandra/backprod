<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Context\StaffContext;
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
 * Features screen, an offer's on the Catalogue, a band's on the product's
 * Story screen. What it could not do is answer the question somebody asks when
 * a language is half finished — *what is missing in Italian?* — because the
 * answer spans tables that have nothing else in common.
 *
 * **This is the operator's words, not the application's.** Field labels,
 * buttons, hints and error wording live in the bundle's JSON catalogues, keyed
 * by the English and proved complete by `gate:i18n` (ADR-050,
 * translatable-fields-spec §1.5). They are not here and this endpoint is not a
 * reason to move them: the test that separates the two is whether the sentence
 * would be identical on every deployment of this platform.
 *
 * **A read and only a read.** Writing goes back through the operations that
 * own each row — `renameFeature`, `renameStaffOffer`, `writeProductStory` —
 * which already carry their permission, their validation and their trail. A
 * second way to write the same rows is the drift the gates exist to prevent.
 *
 * ## Two permissions, and the desk answers with whichever ones you hold
 *
 * The catalogue's words are `staff.catalog.manage`'s — a name a customer is
 * sold is exactly what that permission governs. A product's shop window is
 * `staff.products.manage`'s, decided when the story was built and written into
 * `Version20260924130000`: the page sits beside a product's other settings and
 * is written by whoever creates the product (home-showcase-spec §11.1).
 *
 * They are separate grants, so this route answers the three combinations
 * separately rather than picking one permission and being wrong for two of
 * them:
 *
 * ```text
 * catalog.manage only     features and offers        — exactly today's answer
 * products.manage only    the stories                — a read they are owed
 * both                    everything, one tally      — what PLATFORM_ADMIN is
 * neither                 403
 * ```
 *
 * **A row is on this desk because the caller can fill it.** That is why the
 * answer is filtered rather than widened: showing a catalogue administrator
 * the stories would hand them draft marketing copy the story read keeps behind
 * `staff.products.manage`, and would put sentences in their tally that they
 * cannot write — a screen counting work somebody is refused. Widening either
 * permission to make one query simpler would be the same mistake with the
 * consequence hidden further away.
 *
 * The refusal names `staff.catalog.manage`, which is the desk's own permission
 * and the one its menu entry gates on. Naming two in a field that holds one
 * would be a string no permission catalogue contains.
 */
final class ListTranslationsController implements RouteHandler
{
    public function __construct(private readonly TranslationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffContext::from($request);
        $catalogue = $context->can(StaffPermission::CATALOG_MANAGE);
        $stories = $context->can(StaffPermission::PRODUCTS_MANAGE);

        if (!$catalogue && !$stories) {
            $context->requirePermission(StaffPermission::CATALOG_MANAGE);
        }

        $texts = array_merge(
            $catalogue ? $this->desk->catalogue() : [],
            $stories ? $this->desk->showcase() : [],
        );

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
                    // the platform's own vocabulary (ADR-052), and null for a
                    // story, whose product is already its `code`.
                    'product' => $text->product,
                    // Only what is written. A locale absent is a translation
                    // missing, and the screen counts those — so filling the
                    // gaps with empty strings here would make every sentence
                    // look translated into five languages.
                    'translations' => $text->translations,
                ],
                $texts,
            ),
        ], 200);
    }
}
