<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * Each product's webhook address and the secret that signs what goes there
 * (ADR-051 §5).
 *
 * The secret is issued here and read here, and the two are the only things
 * anybody can do with it: it is shown once, at issue, to be put in the
 * product server's environment, and thereafter exists only sealed in the
 * row and unsealed for the length of a signature.
 */
interface WebhookEndpoints
{
    /**
     * A new secret for the product, in the clear, once. The one it replaces
     * keeps signing for {@see self::OVERLAP_SECONDS}, so the product swaps
     * its copy without a gap.
     *
     * Null when there is no such product.
     *
     * @throws \App\Shared\Exceptions\NotConfiguredException when the deployment
     *                                                        has no key to seal it with
     */
    public function issueSecret(string $productId): ?string;

    /**
     * Where to deliver, and what to sign with — null when the product has no
     * address or no secret, both of which mean "not ready to be told".
     *
     * @throws \App\Shared\Exceptions\NotConfiguredException when a secret is
     *                                                        sealed and the deployment has lost the key
     */
    public function endpoint(string $productId): ?WebhookEndpoint;

    /** How long the previous secret still signs after a rotation. */
    public const OVERLAP_SECONDS = 86_400;
}
