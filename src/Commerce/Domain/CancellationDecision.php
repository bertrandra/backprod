<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * What happens when somebody asks to cancel, and why (§13.1).
 *
 * Deliberately not a boolean. "Can I cancel?" answered yes-or-no is useless to
 * the person asking: what they need to know is *when it takes effect* or
 * *which rule is holding them*, and a boolean carries neither. This is the
 * same shape as the fiscal module's motivated regime decision — when an answer
 * surprises its recipient, a reason can be argued with and a flag cannot.
 *
 * Every request is recorded, accepted or refused. "I cancelled" against "we
 * received nothing" is a dispute with no arbiter unless the platform kept the
 * request and its effective date.
 */
final class CancellationDecision
{
    public const IMMEDIATE = 'IMMEDIATE';
    public const AT_PERIOD_END = 'AT_PERIOD_END';
    public const AT_COMMITMENT_END = 'AT_COMMITMENT_END';
    public const AT_TERM = 'AT_TERM';

    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly string $ruleId,
        public readonly ?string $effect,
        public readonly ?DateTimeImmutable $effectiveAt,
        public readonly ?int $chargeableMonths,
        public readonly array $reasons,
    ) {
    }

    /**
     * @param list<string> $reasons
     */
    public static function accepted(
        string $ruleId,
        string $effect,
        DateTimeImmutable $effectiveAt,
        array $reasons,
        ?int $chargeableMonths = null,
    ): self {
        return new self(true, $ruleId, $effect, $effectiveAt, $chargeableMonths, $reasons);
    }

    /**
     * @param list<string> $reasons
     */
    public static function refused(string $ruleId, array $reasons): self
    {
        return new self(false, $ruleId, null, null, null, $reasons);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'rule_id' => $this->ruleId,
            'effect' => $this->effect,
            'effective_at' => $this->effectiveAt?->format(DATE_ATOM),
            'chargeable_months' => $this->chargeableMonths,
            'reasons' => $this->reasons,
        ];
    }
}
