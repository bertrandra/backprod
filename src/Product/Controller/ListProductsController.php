<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Identity\Service\Profile;
use App\Product\Service\ProductCatalogue;
use App\Shared\Context\IdentityContext;
use App\Shared\Http\RouteHandler;
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
        ], 200);
    }
}
