<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ShowcaseDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/products/{productId}/showcase/publish — puts the
 * story in front of strangers, or takes it back down (2026-09-24).
 *
 * A separate act from writing it, so somebody can write four bands over an
 * afternoon without a stranger reading the half-finished ones. `published`
 * both ways, because a page that could go up and never come down would be
 * a decision nobody could undo.
 *
 * **One language is enough**, and it is English (spec §11.2): a page
 * publishes with the English filled and nothing else, and a reader whose
 * language is unwritten reads English — exactly as they do for a mail, a
 * feature name and an offer name. What is refused is *nothing*:
 * `409 SHOWCASE_INCOMPLETE` while there is no headline, because a shop
 * window with the sign taken down is not a published page.
 */
final class PublishStoryController implements RouteHandler
{
    public function __construct(private readonly ShowcaseDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);
        $published = $body->requiredBool('published');

        $page = $this->desk->publish(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            $published,
        );

        return new JsonResponse(['published_at' => $page?->publishedAt?->format('c')], 200);
    }
}
