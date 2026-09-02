<?php

declare(strict_types=1);

namespace App\Product\Service;

use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Turns the product claim on a request into a resolved product (ADR-013).
 *
 * Takes the raw header value rather than the request itself, so the rule is
 * testable without HTTP and so a later move to subdomains changes only the
 * caller.
 */
final class ProductResolver
{
    public const HEADER = 'X-Product';

    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function resolve(string $claimedCode): Product
    {
        if ($claimedCode === '') {
            // No default: falling back to "the first product" would silently
            // grant access to whichever one happened to be configured first.
            throw new BadRequestException(
                'PRODUCT_CONTEXT_REQUIRED',
                sprintf('The %s header is required.', self::HEADER),
            );
        }

        $product = $this->products->findByCode($claimedCode);

        // An inactive product is reported as absent rather than as disabled:
        // the distinction is of no use to a caller and discloses the roadmap.
        if ($product === null || !$product->active) {
            throw new NotFoundException(
                'Unknown product.',
                ['product' => $claimedCode],
                'PRODUCT_NOT_FOUND',
            );
        }

        return $product;
    }
}
