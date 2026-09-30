<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Service\Sessions;
use App\Commerce\Domain\Places;
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
    /**
     * The feature whose quota bounds a subscription's people.
     *
     * Kept as an alias since 2026-09-25 rather than as a second literal: the
     * organisation's read model needs the same word, and `Places` is where
     * the rule about it now lives.
     */
    public const USERS_FEATURE = Places::USERS_FEATURE;

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

        $this->assertAPlaceIsFree($subscription, $userId);

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
    /**
     * An administrator puts **themselves** on one of the organisation's
     * subscriptions (2026-09-30).
     *
     * **Themselves and nobody else.** There is no id in the request and none
     * in this signature: the only person it can be is the caller, so there is
     * nothing to supply and nothing to check one against — the same shape
     * taking out a seat already has, and for the same reason. Who else a
     * subscription covers stays its owner's decision, which is what
     * `owned()` says and what this deliberately does not touch.
     *
     * **The subscription is verified against the caller's own context**, never
     * taken on trust: an id arrives from a client, and one belonging to
     * another organisation would otherwise be joinable by anybody holding
     * `tenant.manage` anywhere.
     *
     * **No quota check**, because an administrator takes no place. Without
     * that rule this one would spend a seat the customer paid for every time
     * an administrator went to help.
     *
     * @return array{member: SubscriptionMember, invited: bool}
     */
    public function joinAsAdministrator(
        string $tenantId,
        string $productId,
        string $callerId,
        string $subscriptionId,
    ): array {
        $subscription = $this->ofThisOrganisation($tenantId, $productId, $subscriptionId);

        if ($subscription->ownerUserId === $callerId) {
            throw new ConflictException('ALREADY_THE_OWNER', 'The owner is covered already.');
        }

        $this->subscriptions->addMember($subscription->id, $callerId, $callerId);

        foreach ($this->subscriptions->membersOf($subscription->id) as $member) {
            if ($member->userId === $callerId) {
                // Never invited: an administrator has an account already,
                // which is how they came to be administering anything.
                return ['member' => $member, 'invited' => false];
            }
        }

        throw new NotFoundException('The person could not be added.', [], 'MEMBER_NOT_FOUND');
    }

    /** And takes themselves off again. Idempotent, like every other removal. */
    public function leaveAsAdministrator(
        string $tenantId,
        string $productId,
        string $callerId,
        string $subscriptionId,
    ): void {
        $subscription = $this->ofThisOrganisation($tenantId, $productId, $subscriptionId);

        $this->subscriptions->removeMember($subscription->id, $callerId);
    }

    /**
     * The subscription that id names, if it is one this organisation holds on
     * this product and has not ended.
     *
     * `PAST_DUE` is admitted: arrears suspend coverage (ADR-060) and are not
     * an exit, so an administrator may still put themselves on a subscription
     * the organisation is behind on — which is very often exactly when
     * somebody needs to go and look.
     */
    private function ofThisOrganisation(string $tenantId, string $productId, string $subscriptionId): Subscription
    {
        $subscription = $this->subscriptions->findById($subscriptionId);

        if (
            $subscription === null
            || $subscription->tenantId !== $tenantId
            || $subscription->productId !== $productId
            || !in_array($subscription->status, [Subscription::ACTIVE, Subscription::PAST_DUE], true)
        ) {
            // One answer for "no such subscription", "not this organisation's"
            // and "over" — or an id becomes a way to probe other tenants.
            throw new NotFoundException(
                'This organisation holds no live subscription with that id.',
                [],
                'NO_SUBSCRIPTION',
            );
        }

        return $subscription;
    }

    /**
     * Refuses when the subscription's places are all taken.
     *
     * **An administrator takes no place** (2026-09-30), so somebody named by
     * id who administers the organisation is added whatever the count says.
     * The number the quota is compared against is the repository's, not a
     * count of `membersOf()`: it is the same figure `places_used` shows on
     * the organisation screen, and a number a customer paid for must not be
     * computed twice.
     *
     * **Somebody named by address is held to the quota**, deliberately. An
     * invitation creates an account, and an account with no membership holds
     * no role — so a person invited by address is never an administrator, and
     * resolving the address first to find out would mean creating the account
     * before deciding whether to refuse. An existing colleague who does
     * administer can be named by id instead, which is the path that skips it.
     */
    private function assertAPlaceIsFree(Subscription $subscription, ?string $userId): void
    {
        $quota = self::quotaOf($subscription);

        if ($quota === null) {
            return;
        }

        if ($userId !== null && $this->subscriptions->administersSubscription($subscription->id, $userId)) {
            return;
        }

        $used = $this->subscriptions->placesUsedBy($subscription->id);

        if ($used >= $quota) {
            throw new ConflictException(
                'PEOPLE_QUOTA_REACHED',
                sprintf('This subscription covers %d %s, and they are all taken.', $quota, $quota === 1 ? 'person' : 'people'),
                ['quota' => $quota, 'used' => $used],
            );
        }
    }

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
                return Places::sold(true, $grant->limit);
            }
        }

        return Places::sold(false, null);
    }
}
