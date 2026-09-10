<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\RouteHandler;
use App\Skin\Service\TenantSkin;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/tenant/skin/logo — the raw bytes are the request.
 *
 * The same shape as uploading an asset, and for the same reason: a
 * base64 field inside JSON would inflate the payload by a third and would
 * put an image inside a document this platform elsewhere refuses to let
 * images into (non-negotiable #9).
 *
 * The type is sniffed from the bytes and checked **before** anything is
 * stored, so a refused logo leaves nothing behind. `X-Filename` is a label
 * for humans; it decides nothing.
 */
final class UploadSkinLogoController implements RouteHandler
{
    public function __construct(private readonly TenantSkin $skins)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SkinRoute::manageable($request);

        $contents = (string) $request->getBody();

        if ($contents === '') {
            throw new BadRequestException('UPLOAD_EMPTY', 'The request body is the file, and it is empty.');
        }

        $filename = $request->getHeaderLine('X-Filename');

        $skin = $this->skins->setLogo(
            $context->tenantId,
            $context->productId,
            $contents,
            $filename === '' ? 'logo' : $filename,
            $context->userId,
        );

        return new JsonResponse(['skin' => SkinPresenter::skin($skin)], 201);
    }
}
