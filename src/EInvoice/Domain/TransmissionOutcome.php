<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

/**
 * What a platform delivery actually did. Named outcomes rather than a
 * boolean, for the same reason the payment webhook has them: "we did nothing"
 * has several meanings and an operator needs to know which.
 */
final class TransmissionOutcome
{
    public const APPLIED = 'APPLIED';
    public const IGNORED_STALE = 'IGNORED_STALE';
    public const IGNORED_UNKNOWN_TRANSMISSION = 'IGNORED_UNKNOWN_TRANSMISSION';
    public const IGNORED_NOT_APPLICABLE = 'IGNORED_NOT_APPLICABLE';
    public const DUPLICATE = 'DUPLICATE';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $transmissionId,
    ) {
    }

    public static function of(string $outcome, ?string $transmissionId = null): self
    {
        return new self($outcome, $transmissionId);
    }

    public function changedSomething(): bool
    {
        return $this->outcome === self::APPLIED;
    }
}
