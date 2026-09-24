<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Service\ShowcasePictures;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/products/{code}/showcase/assets/{assetId} — the bytes
 * of a picture on a published page (2026-09-24).
 *
 * **Public because the page is**, and only while it is: the read joins the
 * product and answers 404 the moment the story is taken down. One
 * non-answer for an unpublished product, an unknown id and another
 * product's picture, so an id is not a way to ask what a draft contains.
 *
 * Not a signed link. `downloadAsset` is signed because a project's file
 * belongs to a tenant and the link is minted by somebody who may see it;
 * here the reader has no session to mint one with, and what is being
 * served is marketing material somebody chose to publish.
 *
 * **The sniffed type is what is sent**, never a claim from the upload —
 * and `nosniff`, so a browser does not improve on it. Cached hard and
 * immutably: the id names *these* bytes and a new picture is a new id.
 */
final class PublicShowcaseAssetController implements RouteHandler
{
    public function __construct(private readonly ShowcasePictures $pictures)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $code = $request->getAttribute('code');
        $assetId = $request->getAttribute('assetId');

        $asset = $this->pictures->published(
            is_string($code) ? $code : '',
            is_string($assetId) ? $assetId : '',
        );

        $body = new Stream('php://temp', 'wb+');
        $body->write($this->pictures->contentsOf($asset));
        $body->rewind();

        return (new Response($body, 200))
            ->withHeader('Content-Type', $asset->contentType)
            ->withHeader('Content-Length', (string) $asset->byteSize)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Never rendered as a document, whatever a browser makes of the
            // bytes: this is the platform's own origin.
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
