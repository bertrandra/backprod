<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ProductShowcase;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\ReaderLanguage;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/products/{code}/showcase — the product's story, to
 * anybody (2026-09-24, `docs/home-showcase-spec.md` §8).
 *
 * **No session, no product context, no permissions.** A stranger has none
 * of the three, which is why the product is named by its *code* in the path
 * and nothing is resolved from a membership. It is the same position the
 * storefront is in.
 *
 * **404 for a draft**, not an empty page: a page with no words would be the
 * platform advertising that a product exists and has nothing to say, which
 * is the enumeration ADR-041 refuses. A product nobody has published, a
 * code nobody has, and a product that does not exist are one answer.
 *
 * **A retired product still answers** (§11.3). Retiring stops the selling,
 * not the record; what it stops is the prices band having anything to show,
 * and the page says that in words rather than showing a blank.
 *
 * **The language comes from `Accept-Language`**, and that is the whole of
 * the answer to a question the specification left open. A stranger has no
 * profile to read a language from, and `?lang=` was removed on 2026-09-23
 * (ADR-050) because a query parameter travels in a link — a page somebody
 * shared could impose a language on whoever opened it next.
 *
 * A header cannot be shared by accident. It is the reader saying which
 * language they are reading in, *now*, which is exactly what the language
 * picker on the storefront means when somebody uses it. Absent or unknown
 * answers English, the key and the fallback everywhere else on this
 * platform.
 */
final class PublicShowcaseController implements RouteHandler
{
    public function __construct(private readonly ProductShowcase $showcase)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $code = $request->getAttribute('code');
        $page = is_string($code) ? $this->showcase->published($code) : null;

        if ($page === null) {
            throw new NotFoundException('No such page.', [], 'SHOWCASE_NOT_FOUND');
        }

        // The rule, and the reasoning for it, live in `ReaderLanguage` — it
        // is the same question the shop window and one offer now answer, and
        // three copies of a fallback would be three answers to it.
        return new JsonResponse(
            ['showcase' => ShowcasePresenter::page($page, ReaderLanguage::of($request))],
            200,
        );
    }
}
