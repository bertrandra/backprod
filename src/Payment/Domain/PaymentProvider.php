<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Billing\Domain\Money;
use App\Shared\Exceptions\UnauthenticatedException;

/**
 * Port for the payment service provider (§24, non-negotiable #17).
 *
 * Everything above this interface is provider-agnostic. Adding a second PSP
 * is a second adapter and a line of configuration — never a branch in the
 * webhook handler, and never a column that means one thing for one provider.
 *
 * Two rules the interface itself encodes:
 *
 * Verification takes the **raw body**, because a signature is over bytes.
 * Parsing first and verifying the result would verify something the provider
 * never signed, which is not verification at all.
 *
 * Nothing here accepts or returns an instrument. A card never reaches this
 * platform: the customer gives it to the provider, and what comes back is a
 * handle.
 */
interface PaymentProvider
{
    /**
     * The name this provider is configured and recorded under. It appears in
     * `payments.provider` and in the webhook path, so it must be stable.
     */
    public function name(): string;

    /**
     * Starts a payment and returns the provider's handle for it.
     *
     * `reference` is this platform's own identifier for what is being paid,
     * passed through so a human comparing the two systems can line them up.
     */
    public function authorize(Money $amount, string $reference): ProviderPayment;

    /**
     * Verifies that a delivery came from the provider.
     *
     * @param string                   $rawBody the bytes exactly as received
     * @param array<array-key, string> $headers
     *
     * @throws UnauthenticatedException when the signature is absent, malformed or wrong
     */
    public function verify(string $rawBody, array $headers): void;

    /**
     * Normalises a verified delivery. Callers must verify first; an
     * implementation may assume the bytes are authentic.
     *
     * Returns null when the delivery is well-formed but describes something
     * this platform does not model — which is not an error, and must not be
     * answered with one, or the provider will retry it forever.
     */
    public function parse(string $rawBody): ?ProviderEvent;

    /**
     * Asks the provider to return money. The refund is not settled until the
     * provider says so through a webhook — this only starts it.
     */
    public function refund(Payment $payment, Money $amount, string $reason): ProviderRefund;
}
