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
use App\Staff\Domain\StaffIdentity;
use App\Webhook\Domain\WebhookDeliveries;
use App\Webhook\Domain\WebhookDelivery;
use App\Webhook\Domain\WebhookEndpoints;
use DateTimeImmutable;

/**
 * The platform's own products.
 *
 * Retiring a product does not touch its tenants, subscriptions or invoices —
 * they stay exactly where they are — but it closes every door into it.
 */
final class ProductDesk
{
    public function __construct(
        private readonly ProductDirectory $products,
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
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $secret;
    }

    /**
     * What was sent to the product lately, delivered or not (ADR-051 §5).
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
     * Puts a parked delivery back on the queue: the one act here that makes
     * the platform send something again.
     */
    public function retryWebhookDelivery(StaffIdentity $staff, string $productId, string $deliveryId): WebhookDelivery
    {
        $delivery = $this->deliveries->retry($productId, $deliveryId);

        if ($delivery === null) {
            throw new NotFoundException('No such delivery on this product.', [], 'WEBHOOK_DELIVERY_NOT_FOUND');
        }

        return $delivery;
    }

    /**
     * Every key a product was issued, live or not (ADR-051 §4). No secret is
     * in it.
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
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $issued;
    }

    public function revokeCredential(StaffIdentity $staff, string $productId, string $credentialId): ProductKey
    {
        $key = $this->keys->revoke($productId, $credentialId);

        if ($key === null) {
            throw new NotFoundException('No such key on this product.', [], 'PRODUCT_KEY_NOT_FOUND');
        }

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
        return $this->products->create($code, $name);
    }

    /**
     * Renames a product, retires it, or brings it back; sets where it sends
     * people (ADR-051), where its signed events go (ADR-051 §5) and where it
     * sits in every list of products (2026-09-23).
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
        ?int $displayOrder = null,
    ): Product {
        $product = $this->products->update($productId, $name, $active, $setAppUrl, $appUrl, $setWebhookUrl, $webhookUrl, $displayOrder);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }
}
