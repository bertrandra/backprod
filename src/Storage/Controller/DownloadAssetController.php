<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\RouteHandler;
use App\Storage\Service\AssetLinks;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/downloads/{assetId}/content?expires=…&signature=…
 *
 * The one asset route reachable without a session, and — like the payment
 * webhook — it authenticates the *request* rather than the caller. The
 * signature is verified before the asset is fetched, so an unsigned request
 * learns nothing, not even whether the id exists.
 *
 * Two headers matter as much as the bytes:
 *
 *   - `Content-Disposition: attachment`, so a browser saves the file rather
 *     than rendering it in this platform's origin. Even with the allowlist,
 *     serving user-supplied bytes inline is how a stored cross-site scripting
 *     hole gets built.
 *   - `X-Content-Type-Options: nosniff`, so the browser does not second-guess
 *     the type and execute something on its own initiative.
 */
final class DownloadAssetController implements RouteHandler
{
    public function __construct(
        private readonly Assets $assets,
        private readonly AssetLinks $links,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $assetId = AssetRoute::id($request, 'assetId');

        // Before the lookup. An invalid signature must not be able to tell a
        // real asset id from a made-up one by how long the answer takes or
        // what it says.
        $this->links->verify(
            $assetId,
            AssetRoute::queryString($request, 'expires'),
            AssetRoute::queryString($request, 'signature'),
        );

        $asset = $this->assets->forSignedLink($assetId);

        $stream = new Stream('php://temp', 'wb+');
        $stream->write($this->assets->contentsOf($asset));
        $stream->rewind();

        return new Response($stream, 200, [
            'Content-Type' => $asset->contentType,
            'Content-Length' => (string) $asset->byteSize,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                // The filename was stripped of quotes, separators and control
                // characters when it was stored, so it cannot break out of
                // this header — but it is re-checked here rather than trusted
                // to have been, because the header is what would break.
                str_replace('"', '', $asset->filename),
            ),
        ]);
    }
}
