<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Shared\Exceptions\BadRequestException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the product a public request is about.
 *
 * Everywhere else on this platform the product arrives as `X-Product` and is
 * resolved by the context chain, which a request with no session never
 * reaches. Here it is a query parameter — the "filtre produit" of a public
 * page — and this is the one place that reads it, so the two storefront
 * endpoints cannot drift on what a missing or malformed code means.
 *
 * A *missing* code is a 400, because the caller has not said what they want
 * and guessing would make the answer depend on a deployment default the
 * caller cannot see. An *unknown* code is not refused here at all: the
 * storefront answers it with an empty window, so a stranger cannot use this
 * endpoint to find out which products a deployment hosts.
 */
final class PublicRoute
{
    public static function productCode(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $code = $query['product'] ?? null;

        if (!is_string($code) || trim($code) === '') {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => 'product', 'requirement' => 'must name a product'],
            );
        }

        // Bounded before it reaches storage. Nothing authenticates this
        // request, so the only thing standing between a stranger and a
        // pathological parameter is what it is checked against here.
        if (mb_strlen($code) > 64) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => 'product', 'requirement' => 'must be at most 64 characters'],
            );
        }

        return trim($code);
    }
}
