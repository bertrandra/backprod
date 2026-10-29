<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Exceptions\UnauthenticatedException;
use App\Shared\Money\Money;

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
     * Whether this provider moves no real money: a test mode, a sandbox, a
     * stub. The console shows it and the checkout says it, because a demo
     * running on real cards and a production running on test cards are the
     * two mistakes that cost the most (ADR-048).
     */
    public function isSandbox(): bool;

    /**
     * What a page needs to load this provider's own component — a Stripe
     * publishable key, an Adyen client key — or null for a provider with no
     * page-side part. Designed by the provider to sit in a page, so it is
     * not a secret and travels in the API (ADR-048).
     */
    public function clientKey(): ?string;

    /**
     * Starts a payment and returns the provider's handle for it.
     *
     * `reference` is this platform's own identifier for what is being paid,
     * passed through so a human comparing the two systems can line them up.
     *
     * `attemptKey` is what tells this attempt from every other one the
     * provider has ever been asked about, for a provider that remembers
     * requests (2026-09-18): the invoice's row id and the attempt number,
     * never the invoice *number*, which restarts at 000001 in every
     * installation and after every reset of the demonstration world — and a
     * repeated number with a different amount is a request the provider
     * refuses, and one with the same amount is yesterday's intent handed
     * back. Null means the reference is the key, for providers with no
     * memory.
     */
    public function authorize(Money $amount, string $reference, ?string $attemptKey = null): ProviderPayment;

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
     * Asks the provider to return money.
     *
     * What comes back carries the provider's own answer as one of
     * {@see Refund::PENDING}, {@see Refund::SUCCEEDED} or {@see Refund::FAILED}
     * (2026-10-29) — never a constant the adapter assumed. A refund still
     * pending settles later, through a webhook.
     *
     * `$attempt` is the ordinal of this refund on the payment — one more than
     * the refunds already recorded against it. Two identical partial refunds
     * are two refunds, and a provider that remembers requests must not hand
     * the second one the first one's answer.
     */
    public function refund(Payment $payment, Money $amount, string $reason, int $attempt = 1): ProviderRefund;

    /**
     * Abandons an attempt nobody completed, so another can replace it
     * (2026-10-29).
     *
     * True when the attempt can no longer collect anything — cancelled now,
     * or already cancelled. False when the provider says it is past that
     * point: collecting, or collected. A caller that gets false must not
     * start a second attempt, or one invoice is paid twice.
     */
    public function cancel(Payment $payment): bool;
}
