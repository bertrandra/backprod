<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Controller\ShowcasePresenter;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ShowcaseDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/products/{productId}/showcase — the story as the
 * console edits it (2026-09-24).
 *
 * Every block with every language beside it, drafts included. This is the
 * only screen that can finish a half-translated page, which is the whole
 * reason it is answered differently from the public read.
 */
final class ShowStoryController implements RouteHandler
{
    public function __construct(private readonly ShowcaseDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $story = $this->desk->story(StaffRoute::id($request, 'productId'));
        $code = $story['product']->code;

        return new JsonResponse([
            'product' => [
                'id' => $story['product']->id,
                'code' => $story['product']->code,
                'name' => $story['product']->name,
            ],
            'published_at' => $story['published_at']?->format('c'),
            'blocks' => array_map(
                static fn (ShowcaseBlock $block): array => ShowcasePresenter::block($block, $code),
                $story['blocks'],
            ),
        ], 200);
    }
}
