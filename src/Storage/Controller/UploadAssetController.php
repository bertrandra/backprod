<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\RouteHandler;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects/{projectId}/assets — the bytes are the body.
 *
 * A raw body rather than multipart: there is no form to parse, no boundary to
 * get wrong, and the request is the file. `X-Filename` carries the label,
 * which is the only thing a multipart part would have added — and the label is
 * for display, never for addressing.
 *
 * The request's own Content-Type is not read at all. Whatever it claims, the
 * type stored is the one sniffed from the bytes.
 */
final class UploadAssetController implements RouteHandler
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::manageable($request);

        $asset = $this->assets->upload(
            $context->tenantId,
            $context->productId,
            AssetRoute::id($request, 'projectId'),
            (string) $request->getBody(),
            $request->getHeaderLine('X-Filename'),
            $context->userId,
        );

        return new JsonResponse(AssetPresenter::one($asset), 201);
    }
}
