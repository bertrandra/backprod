<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What the provider hands back when a payment is started.
 *
 * `clientSecret` is whatever the front end needs to complete the payment —
 * a redirect URL, a session id, an intent secret. It is passed straight
 * through to the caller and never stored: it is short-lived, it is a
 * credential of sorts, and §31 keeps credentials out of the database.
 */
final class ProviderPayment
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $clientSecret,
        public readonly ?string $method,
    ) {
    }
}
