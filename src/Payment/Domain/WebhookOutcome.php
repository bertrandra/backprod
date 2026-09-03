<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What a webhook delivery actually did.
 *
 * Named outcomes rather than a boolean, because "we did nothing" has several
 * meanings and an operator investigating a payment needs to know which. A
 * delivery for a payment nobody has heard of is a different problem from one
 * that arrived after the payment had already moved on.
 */
final class WebhookOutcome
{
    public const APPLIED = 'APPLIED';
    public const IGNORED_STALE = 'IGNORED_STALE';
    public const IGNORED_UNKNOWN_PAYMENT = 'IGNORED_UNKNOWN_PAYMENT';
    public const IGNORED_NOT_APPLICABLE = 'IGNORED_NOT_APPLICABLE';

    /**
     * A delivery this platform has already recorded. It is not one of the
     * stored outcomes above, because nothing is stored for it — the unique
     * index refused the row. It exists so the endpoint can answer 200
     * truthfully: the provider's message is accounted for, and it changed
     * nothing.
     */
    public const DUPLICATE = 'DUPLICATE';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $paymentId,
    ) {
    }

    public static function of(string $outcome, ?string $paymentId = null): self
    {
        return new self($outcome, $paymentId);
    }

    public function changedSomething(): bool
    {
        return $this->outcome === self::APPLIED;
    }
}
