<?php

declare(strict_types=1);

namespace App\EInvoice\Service;

use App\EInvoice\Domain\TransmissionEffect;
use App\EInvoice\Domain\TransmissionOutcome;
use App\EInvoice\Domain\TransmissionRepository;

/**
 * The approved platform's verdicts, arriving as webhooks.
 *
 * Identical in shape to the payment webhook, deliberately: verify the raw
 * bytes before parsing, normalise through the adapter, then record and apply
 * in one transaction where a replay is refused by a unique index. The two are
 * different providers saying different things, but the delivery problem is
 * the same one and it has the same right answer.
 */
final class EInvoiceWebhook
{
    public function __construct(
        private readonly EInvoiceProviders $providers,
        private readonly TransmissionRepository $transmissions,
        private readonly TransmissionEffect $effect,
    ) {
    }

    /**
     * @param array<array-key, string> $headers
     */
    public function handle(string $providerName, string $rawBody, array $headers): TransmissionOutcome
    {
        $provider = $this->providers->named($providerName);

        $provider->verify($rawBody, $headers);

        $event = $provider->parse($rawBody);

        if ($event === null) {
            return TransmissionOutcome::of(TransmissionOutcome::IGNORED_NOT_APPLICABLE);
        }

        return $this->transmissions->apply(
            $event,
            $provider->name(),
            $this->transmissions->findByDocument($provider->name(), $event->providerDocumentId),
            $this->effect,
        );
    }
}
