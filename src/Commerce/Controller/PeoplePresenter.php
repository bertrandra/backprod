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
     * @param array{subscription: \App\Commerce\Domain\Subscription, members: list<SubscriptionMember>, quota: int|null, owner: bool} $view
     *
     * @return array<string, mixed>
     */
    public static function view(array $view): array
    {
        return [
            'subscription_id' => $view['subscription']->id,
            'owner_user_id' => $view['subscription']->ownerUserId,
            'owner' => $view['owner'],
            'quota' => $view['quota'],
            'members' => array_map(self::member(...), $view['members']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function member(SubscriptionMember $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'added_at' => $member->addedAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339),
        ];
    }
}
