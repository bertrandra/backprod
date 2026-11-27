<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\UnstartedPeriod;
use App\Commerce\Domain\UnstartedPeriodRelease;
use PHPUnit\Framework\TestCase;

/**
 * A scenario in which no period is ever rolled ahead of its start.
 *
 * It fails on contact, like the other doubles here that stand for a billing
 * chain a scenario must not reach: a cancellation that goes looking for an
 * invoice to withdraw, where nothing was invoiced ahead, is the bug and not a
 * detail to stub over.
 */
final class NoUnstartedPeriod implements UnstartedPeriodRelease
{
    public function giveBack(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod
    {
        TestCase::fail('A cancellation went to give back a period that was never invoiced ahead.');
    }

    public function applyCancel(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod
    {
        TestCase::fail('A cancellation went to withdraw an invoice that was never raised ahead.');
    }
}
