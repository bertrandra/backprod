<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Controller\ShowcaseBlocks;
use App\Product\Controller\ShowcasePresenter;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ShowcaseDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/products/{productId}/showcase — the story, replaced
 * wholly (2026-09-24).
 *
 * PUT and not PATCH, because the console sends what the page says: a block
 * it left out is one it removed. Merging would make deleting a band
 * impossible without a route whose only purpose was deletion — the same
 * reasoning as a translation set.
 *
 * **Every field is plain text**, checked by {@see ShowcaseBlocks}. No
 * markup is accepted and none is rendered: a rich-text field would be an
 * XSS surface reachable by anybody with `staff.products.manage` and read by
 * everybody with a browser (spec §9).
 *
 * Writing does not publish. That is the next route, and keeping them apart
 * is what lets somebody write four bands over an afternoon without a
 * stranger reading the half-finished ones.
 */
final class WriteStoryController implements RouteHandler
{
    public function __construct(private readonly ShowcaseDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $written = $this->desk->write(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            ShowcaseBlocks::of(JsonBody::of($request), 'blocks'),
        );

        return new JsonResponse([
            'blocks' => array_map(
                static fn (ShowcaseBlock $block): array => ShowcasePresenter::block($block),
                $written,
            ),
        ], 200);
    }
}
