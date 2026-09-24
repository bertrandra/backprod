<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Catalogue;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/features — the capabilities this product sells.
 *
 * Not the same list as a product's built features (M3): this is what can be
 * bought, which is a commercial question rather than an engineering one.
 */
final class ListFeaturesController implements RouteHandler
{
    public function __construct(private readonly Catalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.read');

        // In the caller's own language (2026-09-24): what an operator wrote
        // about their own catalogue is translated, and the reader's profile
        // says which one. The codes, kinds and units are not — they are the
        // API's vocabulary and stay English everywhere.
        return new JsonResponse(
            ['features' => CataloguePresenter::features($this->catalogue->features($context->productId), $context->locale)],
            200,
        );
    }
}
