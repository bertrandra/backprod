<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * Asking a product what it says about itself, behind a port like every other
 * external concern (§12.1).
 *
 * The caller needs "fetch this product's manifest and tell me what came back",
 * and a test needs to say what comes back without a server — the same bargain
 * {@see \App\Webhook\Domain\WebhookTransport} strikes, and the reason both are
 * interfaces rather than a curl call in a service.
 *
 * The adapter never follows a redirect and never throws. A 3xx is an answer: a
 * product whose manifest moved has not declared anything, and following the
 * hop would read a file from a host no operator named.
 */
interface ProductManifests
{
    /** How long a product has to answer. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * @param string $appUrl the product's own address, as staff configured it
     * @param string $code   the product this manifest must name
     */
    public function of(string $appUrl, string $code): ManifestAnswer;
}
