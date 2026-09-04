<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * Which regime governs a supply, where it is taxed, and why.
 *
 * Separate from {@see TaxCalculation} because the two answer different
 * questions and one needs a repository. The rule decides the regime and the
 * place of taxation from facts alone; only then does a service look up the
 * rate in force there on that date. Folding them together would put a rate
 * lookup inside the domain, which is the dependency §37.6 forbids.
 */
final class RegimeDecision
{
    /**
     * @param list<string> $reasons the steps that led here, in order
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $regime,
        public readonly string $countryOfTaxation,
        public readonly bool $reverseCharge,
        public readonly ?string $legalMention,
        public readonly array $reasons,
    ) {
    }

    public function charges(): bool
    {
        return VatRegime::charges($this->regime);
    }
}
