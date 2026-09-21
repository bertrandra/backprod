<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Product\Domain\ProductKey;
use App\Shared\Exceptions\ForbiddenException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The third authority (ADR-051 §4): a product, calling with its own key
 * and no person present.
 *
 * What it carries is the key — and through it the product, which is
 * therefore *derived from the credential*: these routes read no `X-Product`
 * header, and a key issued to one product cannot say it is another. What a
 * key may do is its scopes, a catalogue of its own beside the tenant's
 * permissions and the platform's; none converts into another.
 */
final class ProductContext
{
    public const ATTRIBUTE = 'product_context';

    public function __construct(public readonly ProductKey $key)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        if (!$context instanceof self) {
            throw new RuntimeException('No product context on this request: the route is not under the product policy.');
        }

        return $context;
    }

    public function productId(): string
    {
        return $this->key->productId;
    }

    public function requireScope(string $scope): void
    {
        if (!$this->key->allows($scope)) {
            throw new ForbiddenException(
                'PRODUCT_KEY_SCOPE',
                'This key does not hold the scope this route needs.',
                ['scope' => $scope],
            );
        }
    }
}
