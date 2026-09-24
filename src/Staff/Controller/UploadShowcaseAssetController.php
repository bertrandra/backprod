<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Service\ShowcasePictures;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/products/{productId}/assets — a picture for the
 * product's page (2026-09-24).
 *
 * A raw body rather than multipart, exactly as `uploadAsset` does: there
 * is no form to parse, no boundary to get wrong, and the request *is* the
 * file. `X-Filename` carries the label, which is the only thing a
 * multipart part would have added — and the label is for display, never
 * for addressing.
 *
 * The request's own Content-Type is not read at all. Whatever it claims,
 * the type stored and later served is the one sniffed from the bytes.
 *
 * `staff.products.manage`, like the rest of the story (spec §11.1).
 */
final class UploadShowcaseAssetController implements RouteHandler
{
    public function __construct(private readonly ShowcasePictures $pictures)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $asset = $this->pictures->upload(
            StaffRoute::id($request, 'productId'),
            (string) $request->getBody(),
            $request->getHeaderLine('X-Filename'),
            $context->identity->userId,
        );

        return new JsonResponse([
            'asset' => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'content_type' => $asset->contentType,
                'byte_size' => $asset->byteSize,
            ],
        ], 201);
    }
}
