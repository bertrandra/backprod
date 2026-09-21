<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Webhook\Domain\ProductEvents;

/**
 * The outbox, in memory: what a service would have told the products,
 * for a unit test that has no database to write the row into.
 *
 * @phpstan-type Published array{type: string, product_id: ?string, tenant_id: ?string, detail: array<string, mixed>}
 */
final class RecordingProductEvents implements ProductEvents
{
    /** @var list<Published> */
    public array $published = [];

    public function publish(string $type, string $productId, ?string $tenantId, array $detail): void
    {
        $this->published[] = ['type' => $type, 'product_id' => $productId, 'tenant_id' => $tenantId, 'detail' => $detail];
    }

    public function publishForTenant(string $type, string $tenantId, array $detail): void
    {
        $this->published[] = ['type' => $type, 'product_id' => null, 'tenant_id' => $tenantId, 'detail' => $detail];
    }

    public function publishToAll(string $type, array $detail): void
    {
        $this->published[] = ['type' => $type, 'product_id' => null, 'tenant_id' => null, 'detail' => $detail];
    }
}
