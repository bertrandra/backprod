<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\SubscriptionMember;
use DateTimeInterface;
use DateTimeZone;

/** The people a subscription covers, on the wire (2026-09-19). */
final class PeoplePresenter
{
    /**
     * @param array{subscription: \App\Commerce\Domain\Subscription, members: list<SubscriptionMember>, quota: int|null, owner: bool, places_used: int, administrators: list<string>} $view
     *
     * @return array<string, mixed>
     */
    public static function view(array $view): array
    {
        $owner = $view['subscription']->ownerUserId;

        return [
            'subscription_id' => $view['subscription']->id,
            'owner_user_id' => $owner,
            'owner' => $view['owner'],
            'quota' => $view['quota'],
            // The server's count, administrators excluded (2026-10-05).
            'places_used' => $view['places_used'],
            'owner_administrator' => $owner !== null && in_array($owner, $view['administrators'], true),
            'members' => array_map(
                static fn (SubscriptionMember $member): array => self::member($member, in_array($member->userId, $view['administrators'], true)),
                $view['members'],
            ),
        ];
    }

    /**
     * One person, and whether they take a place: an administrator of the
     * organisation on this product does not (2026-09-30), and the screen says
     * so rather than leaving a count that does not add up.
     *
     * @return array<string, mixed>
     */
    public static function member(SubscriptionMember $member, bool $administrator): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'added_at' => $member->addedAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339),
            'administrator' => $administrator,
        ];
    }
}
