<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Product\Domain\IssuedProductKey;
use App\Product\Domain\Product;
use App\Product\Domain\ProductDirectory;
use App\Product\Domain\ProductKey;
use App\Product\Domain\ProductKeys;
use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Webhook\Domain\WebhookDeliveries;
use App\Webhook\Domain\WebhookDelivery;
use App\Webhook\Domain\WebhookEndpoints;
use DateTimeImmutable;

/**
 * The platform's own products, and who changed them.
 *
 * **Reads are not recorded, writes are** — the same line `StorefrontDesk`
 * draws, for the same reason. Non-negotiable #21 traces staff crossing into a
 * *tenant's* data; the list of what this platform sells is the platform's own,
 * and an administrator looking at it has crossed nothing. Filing a row per
 * look would bury the decisions among them.
 *
 * The writes are recorded because "who switched this product off?" is a
 * question somebody will eventually ask at a bad moment, and `products.active`
 * alone cannot answer it. Retiring a product does not touch its tenants,
 * subscriptions or invoices — they stay exactly where they are — but it closes
 * every door into it, and that is worth a name and a timestamp.
 */
final class ProductDesk
{
    public function __construct(
        private readonly ProductDirectory $products,
        private readonly StaffAccessLog $trail,
        private readonly ProductKeys $keys,
        private readonly ProductRepository $registry,
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookDeliveries $deliveries,
    ) {
    }

    /**
     * A new webhook secret for the product (ADR-051 §5), in the clear, once.
     * The previous one keeps signing for a day, so the product swaps its
     * copy at its own pace.
     */
    public function issueWebhookSecret(StaffIdentity $staff, string $productId): string
    {
        $secret = $this->endpoints->issueSecret($productId);

        if ($secret === null) {
            $this->record($staff, null, $productId, 'UPDATE_MISS', []);

            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        // That it was issued, by whom, and never what it is.
        $this->record($staff, $productId, $productId, 'ISSUE_WEBHOOK_SECRET', []);

        return $secret;
    }

    /**
     * What was sent to the product lately, delivered or not (ADR-051 §5).
     * Not recorded: nothing of a customer's is in it beyond ids.
     *
     * @return list<WebhookDelivery>
     */
    public function webhookDeliveries(string $productId): array
    {
        if ($this->registry->find($productId) === null) {
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $this->deliveries->recent($productId, 50);
    }

    /**
     * Puts a parked delivery back on the queue. Recorded, because it is the
     * one act here that makes the platform send something again.
     */
    public function retryWebhookDelivery(StaffIdentity $staff, string $productId, string $deliveryId): WebhookDelivery
    {
        $delivery = $this->deliveries->retry($productId, $deliveryId);

        if ($delivery === null) {
            $this->record($staff, null, $deliveryId, 'UPDATE_MISS', []);

            throw new NotFoundException('No such delivery on this product.', [], 'WEBHOOK_DELIVERY_NOT_FOUND');
        }

        $this->record($staff, $productId, $delivery->id, 'RETRY_WEBHOOK', ['event_id' => $delivery->eventId, 'event_type' => $delivery->eventType]);

        return $delivery;
    }

    /**
     * Every key a product was issued, live or not (ADR-051 §4). Listing is
     * not recorded: nothing crosses a boundary, and no secret is in it.
     *
     * @return list<ProductKey>
     */
    public function credentials(string $productId): array
    {
        if ($this->registry->find($productId) === null) {
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $this->keys->listFor($productId);
    }

    /**
     * @param list<string> $scopes
     */
    public function issueCredential(StaffIdentity $staff, string $productId, string $label, array $scopes, int $lifetimeDays): IssuedProductKey
    {
        $issued = $this->keys->issue(
            $productId,
            $label,
            $scopes,
            $staff->userId,
            (new DateTimeImmutable())->modify(sprintf('+%d days', $lifetimeDays)),
        );

        if ($issued === null) {
            $this->record($staff, null, $productId, 'UPDATE_MISS', []);

            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        // The key id and the scopes, never the secret: the trail is read by
        // people, and the secret was for one server's environment.
        $this->record($staff, $productId, $issued->key->id, 'ISSUE_PRODUCT_KEY', ['key_id' => $issued->key->keyId, 'label' => $label, 'scopes' => $scopes]);

        return $issued;
    }

    public function revokeCredential(StaffIdentity $staff, string $productId, string $credentialId): ProductKey
    {
        $key = $this->keys->revoke($productId, $credentialId);

        if ($key === null) {
            $this->record($staff, null, $credentialId, 'UPDATE_MISS', []);

            throw new NotFoundException('No such key on this product.', [], 'PRODUCT_KEY_NOT_FOUND');
        }

        $this->record($staff, $productId, $key->id, 'REVOKE_PRODUCT_KEY', ['key_id' => $key->keyId]);

        return $key;
    }

    /**
     * @return list<Product>
     */
    public function products(): array
    {
        return $this->products->all();
    }

    public function create(StaffIdentity $staff, string $code, string $name): Product
    {
        $product = $this->products->create($code, $name);

        $this->record(
            $staff,
            $product->id,
            $product->id,
            'CREATE',
            ['code' => $product->code, 'name' => $product->name],
        );

        return $product;
    }

    /**
     * Renames a product, retires it, or brings it back.
     *
     * Three distinct actions in the trail rather than one `UPDATE` with a
     * payload: somebody reading it later is asking whether a product was
     * switched off, and a row saying only that it was edited cannot answer
     * that. A call that does both records both.
     */
    public function update(
        StaffIdentity $staff,
        string $productId,
        ?string $name,
        ?bool $active,
        bool $setAppUrl = false,
        ?string $appUrl = null,
        bool $setWebhookUrl = false,
        ?string $webhookUrl = null,
    ): Product {
        $product = $this->products->update($productId, $name, $active, $setAppUrl, $appUrl, $setWebhookUrl, $webhookUrl);

        if ($product === null) {
            // Recorded even though nothing changed: a run of these against
            // ids that match nothing is what somebody probing looks like, and
            // a trail holding only successes cannot show it.
            //
            // **With no product id**, only the requested one as the resource.
            // `staff_access_log.product_id` is a foreign key to `products`, so
            // writing an id that matches nothing there would make the audit
            // write fail and turn a 404 into a 500 — which is exactly what it
            // did before an integration test asked for a product that does not
            // exist. `resource_id` is plain text and is where a value somebody
            // supplied belongs.
            $this->record($staff, null, $productId, 'UPDATE_MISS', []);

            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        if ($name !== null) {
            $this->record($staff, $product->id, $product->id, 'RENAME', ['name' => $product->name]);
        }

        if ($setAppUrl) {
            // Where the platform will send people (ADR-051): worth a row of
            // its own, because a wrong address is a phishing page.
            $this->record($staff, $product->id, $product->id, 'SET_APP_URL', ['app_url' => $product->appUrl]);
        }

        if ($setWebhookUrl) {
            // Where signed events go (ADR-051 §5): a wrong address here
            // sends a customer's subscription state to a stranger.
            $this->record($staff, $product->id, $product->id, 'SET_WEBHOOK_URL', ['webhook_url' => $product->webhookUrl]);
        }

        if ($active !== null) {
            $this->record(
                $staff,
                $product->id,
                $product->id,
                $active ? 'REINSTATE' : 'RETIRE',
                ['code' => $product->code],
            );
        }

        return $product;
    }

    /**
     * @param ?string $productId the foreign key, so null unless the product
     *                           actually exists
     * @param string  $resourceId what the caller named, which may be neither a
     *                            product nor a uuid
     * @param array<string, mixed> $detail
     */
    private function record(
        StaffIdentity $staff,
        ?string $productId,
        string $resourceId,
        string $action,
        array $detail,
    ): void {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // No tenant. A product is what tenants belong to, and naming one
            // here would invent a customer this decision was not about.
            null,
            $productId,
            $action,
            'product',
            $resourceId,
            StaffPermission::PRODUCTS_MANAGE,
            $detail,
        ));
    }
}
