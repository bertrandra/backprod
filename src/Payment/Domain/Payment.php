<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Billing\Domain\Money;
use DateTimeImmutable;

/**
 * An attempt to collect money for an invoice.
 *
 * What this platform holds is the provider's handle for the payment and what
 * became of it. It holds no instrument: §24 puts card data outside
 * PostgreSQL entirely, and `method` is a label — "CARD" — not a card.
 */
final class Payment
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $invoiceId,
        public readonly ?string $subscriptionId,
        public readonly string $provider,
        public readonly string $providerPaymentId,
        public readonly string $status,
        public readonly Money $amount,
        public readonly ?string $method,
        public readonly ?string $failureCode,
        public readonly ?string $failureReason,
        public readonly ?DateTimeImmutable $succeededAt,
        public readonly ?DateTimeImmutable $failedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isSettled(): bool
    {
        return PaymentStatus::isSettled($this->status);
    }

    public function permits(string $status): bool
    {
        return PaymentStatus::permits($this->status, $status);
    }
}
