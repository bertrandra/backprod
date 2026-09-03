<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Billing\Domain\Money;
use DateTimeImmutable;

/**
 * Money going back.
 *
 * A chargeback is one of these with a different reason, not a separate kind
 * of thing: the movement of money is identical, and a report asking "what
 * did we pay back last quarter" should not have to know the difference to
 * get the total right. What the reason changes is who decided — us, or the
 * customer's bank.
 */
final class Refund
{
    public const REQUESTED = 'REQUESTED';
    public const DUPLICATE = 'DUPLICATE';
    public const FRAUDULENT = 'FRAUDULENT';
    public const CHARGEBACK = 'CHARGEBACK';

    public const PENDING = 'PENDING';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED = 'FAILED';

    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $provider,
        public readonly ?string $providerRefundId,
        public readonly Money $amount,
        public readonly string $reason,
        public readonly string $status,
        public readonly ?DateTimeImmutable $settledAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A chargeback is imposed rather than granted, which is why it is worth
     * telling apart even though the money moves the same way: it is a
     * dispute, and a tenant collecting them is a commercial problem rather
     * than an accounting one.
     */
    public function isDisputed(): bool
    {
        return $this->reason === self::CHARGEBACK;
    }
}
