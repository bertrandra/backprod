<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * Where a product's events go, and what signs them.
 *
 * `secrets` is one or two: the current secret, and the previous one while
 * its window is open. Both sign, so a product that has not yet swapped its
 * copy still verifies — that is what makes a rotation a change the product
 * does at its own pace rather than a cut-over. In memory only, on the way
 * from the sealed column to the signature; never presented anywhere.
 */
final class WebhookEndpoint
{
    /**
     * @param list<string> $secrets
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $url,
        public readonly array $secrets,
    ) {
    }
}
