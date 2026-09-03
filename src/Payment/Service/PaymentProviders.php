<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Domain\PaymentProvider;
use App\Shared\Exceptions\NotFoundException;

/**
 * The configured payment providers, by name.
 *
 * A registry rather than a single injected provider, because non-negotiable
 * #17 is about not coupling to one: a platform selling through two entities,
 * or migrating from one PSP to another, runs both at once, and payments
 * already in flight with the old one must keep settling.
 *
 * Which one a payment used is recorded on the payment, so nothing has to
 * guess later. A webhook names its provider in the path, and is verified
 * with that provider's secret.
 */
final class PaymentProviders
{
    /**
     * @var array<string, PaymentProvider>
     */
    private readonly array $providers;

    /**
     * @param list<PaymentProvider> $providers the first is the default
     */
    public function __construct(array $providers)
    {
        $byName = [];

        foreach ($providers as $provider) {
            $byName[$provider->name()] = $provider;
        }

        $this->providers = $byName;
    }

    /**
     * The provider new payments are started with.
     *
     * Absence is a server fault, not a client one: a deployment with no
     * payment provider configured cannot take money, and saying so plainly
     * beats a 404 that reads like the customer asked for the wrong thing.
     */
    public function default(): PaymentProvider
    {
        $first = array_values($this->providers)[0] ?? null;

        if ($first === null) {
            throw new NotFoundException(
                'No payment provider is configured.',
                [],
                'PAYMENT_PROVIDER_UNAVAILABLE',
            );
        }

        return $first;
    }

    /**
     * The provider a webhook or an existing payment names.
     *
     * An unknown name is a 404 rather than anything more descriptive: the
     * caller here is unauthenticated, and which providers a platform uses is
     * commercial information.
     */
    public function named(string $name): PaymentProvider
    {
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw new NotFoundException('Unknown payment provider.', [], 'PAYMENT_PROVIDER_UNKNOWN');
        }

        return $provider;
    }
}
