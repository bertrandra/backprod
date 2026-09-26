<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\Domain\SubscriptionPlaces;

/**
 * Remembers who was taken off their organisation's subscriptions.
 *
 * Small because the port is small: one method is the whole of what leaving an
 * organisation does to commerce, so the double has one method too.
 *
 * @phpstan-type Release array{tenantId: string, userId: string}
 */
final class RecordingPlaceRelease implements SubscriptionPlaces
{
    /** @var list<Release> */
    public array $released = [];

    public function __construct(private readonly int $places = 0)
    {
    }

    public function release(string $tenantId, string $userId): int
    {
        $this->released[] = ['tenantId' => $tenantId, 'userId' => $userId];

        return $this->places;
    }
}
