<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\Domain\ChangeCharge;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscription;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The invoice a change of plan raises, remembered rather than written.
 *
 * Used where the subject is the **lifecycle** — what moved, what the period
 * became, what the entitlements are — and not the document. Writing a real
 * invoice there would need a billing profile, a supplier identity and a tax
 * profile, none of which that question turns on; the document itself is
 * exercised where documents are, against the real chain.
 *
 * `quote` answers the version's own price and no tax, which is deliberate:
 * these tests assert that the *right* figure was asked for and that nothing
 * was charged when nothing was outstanding, and a stand-in that invented a
 * rate would make those assertions about the stand-in.
 *
 * @phpstan-type Charged array{subscriptionId: string, offerVersionId: string, periodStart: string, periodEnd: string|null}
 */
final class RecordingChangeCharge implements ChangeCharge
{
    /** @var list<Charged> */
    public array $charged = [];

    public function quote(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $at,
    ): int {
        return $offer->version->priceMinorUnits;
    }

    public function applyCharge(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string {
        $this->charged[] = [
            'subscriptionId' => $subscription->id,
            'offerVersionId' => $offer->version->id,
            'periodStart' => $periodStart->format(DateTimeInterface::ATOM),
            'periodEnd' => $periodEnd?->format(DateTimeInterface::ATOM),
        ];

        return 'invoice-' . count($this->charged);
    }
}
