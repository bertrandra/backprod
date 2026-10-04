<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use App\Tenant\Service\TenantLogo;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/tenants/current/logo — the raw bytes are the request
 * (2026-10-05).
 *
 * The same shape as uploading an asset, and for the same reason: a base64
 * field inside JSON would inflate the payload by a third and put an image
 * inside a document (non-negotiable #9). The type is sniffed from the bytes
 * before anything is stored; `X-Filename` is a label for humans.
 *
 * The organisation's administrator's (`tenant.manage`), like its name. No
 * entitlement: a logo is part of who the organisation is, not something an
 * offer sells.
 */
final class UploadTenantLogoController implements RouteHandler
{
    public function __construct(
        private readonly TenantLogo $logos,
        private readonly Joining $joining,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $contents = (string) $request->getBody();

        if ($contents === '') {
            throw new BadRequestException('UPLOAD_EMPTY', 'The request body is the file, and it is empty.');
        }

        $filename = $request->getHeaderLine('X-Filename');

        $tenant = $this->logos->set(
            $context->tenantId,
            $context->productId,
            $contents,
            $filename === '' ? 'logo' : $filename,
            $context->userId,
        );

        return new JsonResponse(TenantPresenter::one($tenant, $this->joining->policy($context->tenantId)), 201);
    }
}
