<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Identity\Service\Profile;
use App\Product\Service\ProductCatalogue;
use App\Shared\Context\IdentityContext;
use App\Shared\Http\RouteHandler;
use App\Tenant\Domain\JoinRequests;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/products — the products this caller may use.
 *
 * Identity-only: a client cannot name a product before it knows which ones
 * it has, so this is where X-Product comes from rather than something it
 * requires.
 */
final class ListProductsController implements RouteHandler
{
    public function __construct(
        private readonly ProductCatalogue $catalogue,
        private readonly Profile $profile,
        private readonly JoinRequests $requests,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);

        return new JsonResponse([
            'products' => ProductPresenter::many($this->catalogue->available($identity->userId)),
            // Beside the list rather than on `/me`, because `/me` needs a
            // product and this is how the shell learns which one to name
            // first: the person's own default, among what they hold.
            'default' => $this->profile->defaultProductCode($identity->userId),
            // The organisations this person belongs to, by slug (2026-09-18):
            // how the shell puts the address under the right root after a
            // sign-in — a member of Acme who signed in at the bare host
            // belongs at `/acme/`, and the slug is what the root is made of.
            'memberships' => array_map(
                static fn (array $membership): array => ['tenant' => $membership['slug'], 'name' => $membership['name']],
                $this->requests->memberOf($identity->userId),
            ),
            // The organisations this person asked to join and is waiting on
            // (2026-09-17). Here rather than on `/me`, for the same reason
            // as the default: somebody with no live membership has no
            // product to ask `/me` with, and this is how the shell learns
            // to say "waiting" rather than "nothing".
            'pending_memberships' => array_map(
                static fn (array $request): array => ['tenant' => $request['slug'], 'name' => $request['name']],
                $this->requests->pendingFor($identity->userId),
            ),
        ], 200);
    }
}
