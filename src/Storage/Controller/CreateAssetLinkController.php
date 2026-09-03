<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Storage\Service\AssetLinks;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/assets/{assetId}/link — mint a short-lived download URL.
 *
 * Authenticated and tenant-scoped: this is where the platform checks that the
 * caller may see the asset. The link it hands back is what carries that
 * decision to a browser, which cannot send a bearer token from an `<img>`
 * tag or a download click.
 */
final class CreateAssetLinkController implements RouteHandler
{
    public function __construct(
        private readonly Assets $assets,
        private readonly AssetLinks $links,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::readable($request);

        $asset = $this->assets->show(
            $context->tenantId,
            $context->productId,
            AssetRoute::id($request, 'assetId'),
        );

        $link = $this->links->mint($asset, $this->lifetimeIn($request));

        return new JsonResponse($link, 201);
    }

    /**
     * Minting a link with the default lifetime needs no body, so an absent
     * one is not an error. Only a request that actually asks for something is
     * parsed — demanding `{}` from a client with nothing to say is a rule
     * that serves nobody.
     */
    private function lifetimeIn(ServerRequestInterface $request): int
    {
        if (trim((string) $request->getBody()) === '') {
            return AssetLinks::DEFAULT_TTL_SECONDS;
        }

        $body = JsonBody::of($request);

        return $body->has('ttl_seconds')
            ? $body->requiredInt('ttl_seconds')
            : AssetLinks::DEFAULT_TTL_SECONDS;
    }
}
