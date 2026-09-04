<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * The answer a {@see VatNumberValidator} gives.
 *
 * `UNAVAILABLE` is not a failure to be retried silently and it is not a
 * refusal: it is "we asked and got no answer", which §25.2 has to be able to
 * show as an anomaly. What it is definitely not is a verification.
 */
final class VatNumberCheck
{
    public const VALID = 'VALID';
    public const INVALID = 'INVALID';
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * @param array<string, mixed> $evidence what the provider said, kept as proof
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $registeredName,
        public readonly ?string $registeredAddress,
        public readonly array $evidence,
    ) {
    }

    public function isValid(): bool
    {
        return $this->outcome === self::VALID;
    }

    /**
     * The identification status this check produces.
     *
     * Only VALID becomes VERIFIED. Everything else, including a provider
     * outage, leaves the number unproved — which is what fail-closed means
     * in practice rather than in principle.
     */
    public function identificationStatus(): string
    {
        return match ($this->outcome) {
            self::VALID => TaxIdentification::VERIFIED,
            self::INVALID => TaxIdentification::INVALID,
            default => TaxIdentification::UNAVAILABLE,
        };
    }
}
