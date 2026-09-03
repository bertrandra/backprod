<?php

declare(strict_types=1);

namespace App\EInvoice\Service;

use App\EInvoice\Domain\EInvoiceProvider;
use App\Shared\Exceptions\NotFoundException;

/**
 * The configured approved platforms, by name.
 *
 * §25.1 requires the choice of platform to stay interchangeable, and a
 * registry is what makes that true in practice rather than in principle: a
 * migration between PDPs runs both while documents already lodged with the
 * old one are still being ruled on.
 */
final class EInvoiceProviders
{
    /**
     * @var array<string, EInvoiceProvider>
     */
    private readonly array $providers;

    /**
     * @param list<EInvoiceProvider> $providers the first is the default
     */
    public function __construct(array $providers)
    {
        $byName = [];

        foreach ($providers as $provider) {
            $byName[$provider->name()] = $provider;
        }

        $this->providers = $byName;
    }

    public function default(): EInvoiceProvider
    {
        $first = array_values($this->providers)[0] ?? null;

        if ($first === null) {
            throw new NotFoundException(
                'No e-invoicing platform is configured.',
                [],
                'EINVOICE_PROVIDER_UNAVAILABLE',
            );
        }

        return $first;
    }

    public function named(string $name): EInvoiceProvider
    {
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw new NotFoundException('Unknown e-invoicing platform.', [], 'EINVOICE_PROVIDER_UNKNOWN');
        }

        return $provider;
    }
}
