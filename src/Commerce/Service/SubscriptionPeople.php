<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Service\Sessions;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionMember;
use App\Commerce\Domain\SubscriptionRepository;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\NotFoundException;

/**
 * The people a subscription covers, managed by its owner (2026-09-19).
 *
 * An offer may sell a number of users — the `users` quota — and whoever
 * activated the subscription is its owner: the person a seat is for, or
 * the administrator who bought the organisation's. The owner adds and
 * removes people within that quota, from the organisation's members or by
 * address; somebody named by address who has no account gets one, and an
 * invitation link to set their password. Entitlement resolution counts the
 * people: a member of Ada's seat is entitled by it.
 *
 * The quota counts the owner. An offer with no `users` quota covers the
 * owner alone — a seat is one person's unless it says otherwise — and a
 * null limit is unlimited.
 */
final class SubscriptionPeople
{
    /** The feature whose quota bounds a subscription's people. A platform convention, not a product's. */
    public const USERS_FEATURE = 'users';

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Subscriptions $lifecycle,
        private readonly AccountRegistrar $registrar,
        private readonly Sessions $sessions,
    ) {
    }

    /**
     * @return array{subscription: Subscription, members: list<SubscriptionMember>, quota: int|null, owner: bool}
     */
    public function of(string $tenantId, string $productId, string $callerId, bool $seat): array
    {
        $subscription = $this->managed($tenantId, $productId, $callerId, $seat);

        return [
            'subscription' => $subscription,
            'members' => $this->subscriptions->membersOf($subscription->id),
            'quota' => self::quotaOf($subscription),
            'owner' => $subscription->ownerUserId === $callerId,
        ];
    }

    /**
     * Adds a person: an existing member of the organisation by id, or
     * anybody by address.
     *
     * @return array{member: SubscriptionMember, invited: bool}
     */
    public function add(string $tenantId, string $productId, string $callerId, bool $seat, ?string $userId, ?string $email): array
    {
        $subscription = $this->owned($tenantId, $productId, $callerId, $seat);
        $members = $this->subscriptions->membersOf($subscription->id);
        $quota = self::quotaOf($subscription);

        // The owner is one of the quota's people.
        if ($quota !== null && count($members) + 1 >= $quota) {
            throw new ConflictException(
                'PEOPLE_QUOTA_REACHED',
                sprintf('This subscription covers %d %s, and they are all taken.', $quota, $quota === 1 ? 'person' : 'people'),
                ['quota' => $quota, 'members' => count($members)],
            );
        }

        $invited = false;

        if ($userId === null) {
            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BadRequestException('VALIDATION_FAILED', 'Name a member by id, or somebody by a valid email address.');
            }

            $account = $this->registrar->invite($email, $tenantId);
            $userId = $account['user_id'];
            $invited = $account['created'];
        }

        if ($userId === $subscription->ownerUserId) {
            throw new ConflictException('ALREADY_THE_OWNER', 'The owner is covered already.');
        }

        $this->subscriptions->addMember($subscription->id, $userId, $callerId);

        if ($invited && $email !== null) {
            // The account exists with no usable password: the link is how
            // they set one, and where they learn they were added.
            $this->sessions->sendPasswordLink($userId, $email, Sessions::INVITATION);
        }

        foreach ($this->subscriptions->membersOf($subscription->id) as $member) {
            if ($member->userId === $userId) {
                return ['member' => $member, 'invited' => $invited];
            }
        }

        throw new NotFoundException('The person could not be added.', [], 'MEMBER_NOT_FOUND');
    }

    public function remove(string $tenantId, string $productId, string $callerId, bool $seat, string $userId): void
    {
        $subscription = $this->owned($tenantId, $productId, $callerId, $seat);

        $this->subscriptions->removeMember($subscription->id, $userId);
    }

    /**
     * The subscription the caller means: their own seat, or the
     * organisation's — live, or there is nothing to manage.
     */
    private function managed(string $tenantId, string $productId, string $callerId, bool $seat): Subscription
    {
        $subscription = $seat
            ? $this->lifecycle->seatOf($tenantId, $productId, $callerId)
            : $this->lifecycle->current($tenantId, $productId);

        if ($subscription === null) {
            throw new NotFoundException(
                $seat ? 'You hold no live seat on this product.' : 'The organisation has no live subscription to this product.',
                [],
                'NO_SUBSCRIPTION',
            );
        }

        return $subscription;
    }

    private function owned(string $tenantId, string $productId, string $callerId, bool $seat): Subscription
    {
        $subscription = $this->managed($tenantId, $productId, $callerId, $seat);

        if ($subscription->ownerUserId !== $callerId) {
            // Not a permission: the owner is whoever activated it, and only
            // they decide who it covers.
            throw new ForbiddenException('NOT_THE_OWNER', 'Only the person who activated this subscription manages its people.');
        }

        return $subscription;
    }

    /** The `users` quota the offer sold, counting the owner; 1 when it sold none; null for unlimited. */
    public static function quotaOf(Subscription $subscription): ?int
    {
        foreach ($subscription->offer->version->grants as $grant) {
            if ($grant->feature->code === self::USERS_FEATURE) {
                return $grant->limit;
            }
        }

        return 1;
    }
}
