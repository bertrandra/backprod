<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Delivering one notification on one channel (§27.1), as a port.
 *
 * The fifth provider port in the platform, after `PaymentProvider`,
 * `EInvoiceProvider`, `StorageProvider` and `VatNumberValidator`, and it
 * follows the same discipline: no provider name reaches the domain, so
 * changing SMS routers is a change of wiring rather than of business code.
 *
 * An implementation is responsible for one channel and says which. It is not
 * responsible for deciding whether to send — consent, preferences and
 * suppression are settled before it is called, because a channel adapter that
 * could also refuse would be two decisions in two places.
 */
interface Notifier
{
    public function channel(): string;

    /**
     * @param array<string, mixed> $payload
     * @throws \RuntimeException when the provider refuses or is unreachable
     */
    public function send(string $address, string $subject, string $body, array $payload): string;
}
