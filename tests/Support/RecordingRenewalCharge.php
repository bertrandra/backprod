<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\Domain\RenewalCharge;
use App\Commerce\Domain\Subscription;
use DateTimeImmutable;

/**
 * The invoice a renewal raises, remembered rather than written.
 *
 * Used where the subject is the **lifecycle** — what moved, what the period
 * became, what the entitlements are — and not the document. Writing a real
 * invoice there would need a billing profile, a supplier identity and a tax
 * profile, none of which that question turns on; the document itself is
 * exercised where documents are, against the real chain.
 *
 * It records the **period** it was asked to bill, which is the one thing these
 * tests do need to assert about it: a renewal billed from `now()` rather than
 * from the period that ended is a customer charged for the wrong days, and
 * nothing downstream would notice.
 *
 * @phpstan-type Billed array{subscriptionId: string, periodStart: string, periodEnd: string}
 */
final class RecordingRenewalCharge implements RenewalCharge
{
    /** @var list<Billed> */
    public array $billed = [];

    public function applyRenewal(
        Subscription $subscription,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string {
        $this->billed[] = [
            'subscriptionId' => $subscription->id,
            'periodStart' => $periodStart->format('Y-m-d'),
            'periodEnd' => $periodEnd->format('Y-m-d'),
        ];

        return '00000000-0000-4000-8000-' . sprintf('%012d', count($this->billed));
    }
}
