<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentSettlement;
use App\Payment\Domain\WebhookOutcome;

/**
 * The server webhook, which §24 makes the source of truth.
 *
 * The order of the four steps is the design:
 *
 *  1. resolve the provider named in the path;
 *  2. verify the signature **over the raw bytes**, before anything is parsed —
 *     a signature is over bytes, and verifying a parsed structure verifies
 *     something the provider never signed;
 *  3. normalise the delivery through that provider's adapter, so nothing
 *     below here knows what the provider called its event;
 *  4. record and apply it in one transaction, where a replay is refused by
 *     a unique index rather than by a check that could be raced.
 *
 * Nothing in the body is trusted before step 2, including the payment it
 * claims to be about. After step 2 the bytes are known to have come from the
 * provider, which is what makes it safe to resolve a tenant from them
 * without a client-supplied tenant id (ADR-015).
 */
final class PaymentWebhook
{
    public function __construct(
        private readonly PaymentProviders $providers,
        private readonly PaymentRepository $payments,
        private readonly PaymentSettlement $settlement,
    ) {
    }

    /**
     * @param array<array-key, string> $headers
     */
    public function handle(string $providerName, string $rawBody, array $headers): WebhookOutcome
    {
        $provider = $this->providers->named($providerName);

        $provider->verify($rawBody, $headers);

        $event = $provider->parse($rawBody);

        if ($event === null) {
            // Well-formed but not something this platform models. Not an
            // error, and it must not be answered with one: a provider
            // retries anything that is not a 2xx, and an event nobody will
            // ever handle would be retried until it gave up — burying the
            // deliveries that do matter.
            return WebhookOutcome::of(WebhookOutcome::IGNORED_NOT_APPLICABLE);
        }

        return $this->payments->apply(
            $event,
            $provider->name(),
            $this->payments->findByReference($provider->name(), $event->providerPaymentId),
            $this->settlement,
        );
    }
}
