<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\Domain\ChangeCredit;
use App\Commerce\Domain\CollectedPeriod;
use App\Commerce\Domain\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * A subscription nobody ever paid for, and what that means for a proration.
 *
 * Nothing collected is an **answer** and not an error (spec §3.3): a period no
 * money bought has no unconsumed value, so the credit is zero and the reason
 * travels with it. Refusing the change would trap somebody on a plan nobody is
 * paying for; crediting anyway would return money that never arrived.
 *
 * So `giveBack` fails on contact. Reaching for it in a scenario where nothing
 * was collected is the bug, not a detail to stub over — it would be the
 * platform sending money against a payment it has not found.
 */
final class NothingWasCollected implements ChangeCredit
{
    public function __construct(private readonly string $currency = 'EUR')
    {
    }

    public function collectedFor(Subscription $subscription): CollectedPeriod
    {
        return CollectedPeriod::nothing(
            $this->currency,
            'Nothing has been collected for the current period, so there is no unconsumed value to give back.',
        );
    }

    public function giveBack(
        Subscription $subscription,
        CollectedPeriod $collected,
        int $amountMinorUnits,
        ?string $actorUserId,
    ): string {
        TestCase::fail('A credit was returned against a period nothing was ever collected for.');
    }
}
