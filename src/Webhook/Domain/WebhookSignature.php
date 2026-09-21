<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * `X-Backprod-Signature: t=<unix>,v1=<hex>[,v1=<hex>]` — the mirror of what
 * the platform verifies on Stripe's deliveries (ADR-048), so a product
 * developer can copy a known-good verifier rather than write one.
 *
 * Each `v1` is an HMAC-SHA256 over `"<t>.<raw body>"` with one of the
 * secrets in force; two appear during a rotation. The timestamp is the
 * moment of *this attempt*, not of the event, so a delivery retried a day
 * later is still inside the product's replay window.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Backprod-Signature';

    /**
     * @param list<string> $secrets
     */
    public static function header(string $rawBody, array $secrets, int $now): string
    {
        $parts = ['t=' . $now];

        foreach ($secrets as $secret) {
            $parts[] = 'v1=' . hash_hmac('sha256', $now . '.' . $rawBody, $secret);
        }

        return implode(',', $parts);
    }
}
