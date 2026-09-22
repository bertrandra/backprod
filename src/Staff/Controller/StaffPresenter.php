<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\Product;
use App\Product\Domain\ProductKey;
use App\Staff\Domain\GrantedEntitlement;
use App\Staff\Domain\GrantedFeature;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\TenantAccount;
use App\Staff\Domain\TenantMemberAcrossProducts;
use App\Webhook\Domain\WebhookDelivery;
use DateTimeInterface;
use DateTimeZone;

final class StaffPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function identity(StaffIdentity $staff): array
    {
        return [
            'user_id' => $staff->userId,
            'email' => $staff->email,
            'display_name' => $staff->displayName,
            'locale' => $staff->locale,
            'roles' => $staff->roles,
            'permissions' => $staff->permissions,
        ];
    }

    /**
     * One member of a tenant, seen from the console: who, what they may do,
     * and on which products. Null name and address once erased (§26).
     *
     * @return array<string, mixed>
     */
    public static function tenantMember(TenantMemberAcrossProducts $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'roles' => $member->roles,
            'products' => $member->products,
        ];
    }

    /**
     * One person on the roster.
     *
     * `email` and `display_name` are nullable because §26's erasure empties
     * them: an erased user who still holds a platform role must still appear,
     * since a console that hid the row would be hiding authority nobody could
     * then revoke.
     *
     * @return array<string, mixed>
     */
    public static function member(StaffMember $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'roles' => $member->roles,
            'granted_at' => $member->grantedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339),
        ];
    }

    /**
     * A product key as the console shows it (ADR-051 §4): never the secret.
     *
     * @return array{id: string, key_id: string, label: string, scopes: list<string>, created_at: string, expires_at: ?string, revoked_at: ?string, last_used_at: ?string}
     */
    public static function credential(ProductKey $key): array
    {
        return [
            'id' => $key->id,
            'key_id' => $key->keyId,
            'label' => $key->label,
            'scopes' => $key->scopes,
            'created_at' => $key->createdAt->format(DATE_ATOM),
            'expires_at' => $key->expiresAt?->format(DATE_ATOM),
            'revoked_at' => $key->revokedAt?->format(DATE_ATOM),
            'last_used_at' => $key->lastUsedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * A product as the platform's own administrator sees it.
     *
     * `active` is here and is absent from every other product shape on this
     * platform, because every other one has already filtered on it — the
     * context chain treats an inactive product as absent, and so does the
     * storefront. This is the one view where a retired product is a row
     * rather than a silence.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'active' => $product->active,
            'app_url' => $product->appUrl,
            'webhook_url' => $product->webhookUrl,
            'webhook_secret_issued_at' => $product->webhookSecretIssuedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * One event on its way to a product (ADR-051 §5): the envelope and what
     * became of it, never the payload — a tenant's subscription state is
     * the product's to read on its own route, not this screen's.
     *
     * @return array<string, mixed>
     */
    public static function webhookDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event_id' => $delivery->eventId,
            'event_type' => $delivery->eventType,
            'tenant_id' => $delivery->tenantId,
            'occurred_at' => $delivery->occurredAt->format(DATE_ATOM),
            'attempt' => $delivery->attempt,
            'next_attempt_at' => $delivery->nextAttemptAt->format(DATE_ATOM),
            'delivered_at' => $delivery->deliveredAt?->format(DATE_ATOM),
            'parked_at' => $delivery->parkedAt?->format(DATE_ATOM),
            'last_status' => $delivery->lastStatus,
            'last_error' => $delivery->lastError,
        ];
    }

    /**
     * What the platform gave a tenant on one product (docs/tenant-roots.md
     * §2.8): the features with their limits, until when, by whom, since when.
     *
     * @return array<string, mixed>
     */
    public static function grant(GrantedEntitlement $granted): array
    {
        return [
            'tenant_id' => $granted->tenantId,
            'product_id' => $granted->productId,
            'features' => array_map(static fn (GrantedFeature $feature): array => [
                'code' => $feature->code,
                'name' => $feature->name,
                'kind' => $feature->kind,
                'limit' => $feature->limit,
            ], $granted->features),
            'valid_until' => $granted->validUntil?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339),
            'granted_by' => $granted->grantedBy,
            'granted_at' => $granted->grantedAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tenant(TenantAccount $account): array
    {
        $tenant = $account->tenant;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'may_author_offers' => $tenant->mayAuthorOffers,
            'is_default' => $account->isDefault,
            // Which products the platform has given this tenant (ADR-047):
            // the platform's answer, so it is on the staff shape and not on
            // the `Tenant` a tenant reads about itself.
            'products' => array_map(self::product(...), $account->products),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function accessEntry(StaffAccessEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'staff_user_id' => $entry->staffUserId,
            'tenant_id' => $entry->tenantId,
            'product_id' => $entry->productId,
            'action' => $entry->action,
            'resource_type' => $entry->resourceType,
            'resource_id' => $entry->resourceId,
            'permission' => $entry->permission,
            'detail' => $entry->detail,
            'occurred_at' => $entry->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339),
        ];
    }
}
