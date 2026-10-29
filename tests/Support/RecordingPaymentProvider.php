<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentProvider;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\ProviderRefund;
use App\Payment\Domain\Refund;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Shared\Money\Money;

/**
 * The stub provider, recording what it was asked and answering what a test
 * chooses (2026-10-29): whether an attempt can still be abandoned, and what a
 * refund comes back as.
 *
 * What it proves is the order of things the platform does around a provider
 * that cannot be unasked — that a refund refused under the invoice's lock was
 * never put to the provider, that a live attempt is abandoned before another
 * is started — which the stub alone, answering everything the same way, does
 * not let a test see.
 */
final class RecordingPaymentProvider implements PaymentProvider
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(
        private readonly StubPaymentProvider $stub,
        private readonly bool $cancellable = true,
        private readonly string $refundStatus = Refund::PENDING,
    ) {
    }

    public function name(): string
    {
        return $this->stub->name();
    }

    public function isSandbox(): bool
    {
        return $this->stub->isSandbox();
    }

    public function clientKey(): ?string
    {
        return $this->stub->clientKey();
    }

    public function authorize(Money $amount, string $reference, ?string $attemptKey = null): ProviderPayment
    {
        $this->asked[] = 'authorize';

        return $this->stub->authorize($amount, $reference, $attemptKey);
    }

    public function verify(string $rawBody, array $headers): void
    {
        $this->stub->verify($rawBody, $headers);
    }

    public function parse(string $rawBody): ?ProviderEvent
    {
        return $this->stub->parse($rawBody);
    }

    public function refund(Payment $payment, Money $amount, string $reason, int $attempt = 1): ProviderRefund
    {
        $this->asked[] = 'refund';

        return new ProviderRefund($this->stub->refund($payment, $amount, $reason, $attempt)->id, $this->refundStatus);
    }

    public function cancel(Payment $payment): bool
    {
        $this->asked[] = 'cancel';

        return $this->cancellable;
    }
}
