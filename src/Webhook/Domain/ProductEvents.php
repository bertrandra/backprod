<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * Telling a product what happened (ADR-051 §5), from the act that made it
 * happen.
 *
 * Publishing writes the outbox row and nothing else: the delivery is the
 * queue's, later, so a product whose server is down makes nothing on the
 * platform slow. A product that has given no address hears nothing — the
 * row is not written, rather than written and never sent — because a
 * backlog delivered the day an address is set would be last month's news
 * arriving as if it were today's.
 *
 * The payload names ids and states, never money and never a credential
 * (§5): the product fetches anything richer through the `product/*` routes,
 * where the read is authorised and logged.
 */
interface ProductEvents
{
    /**
     * One event, to one product.
     *
     * @param array<string, mixed> $detail what goes in the payload beside the
     *                                     envelope (event_id, type, occurred_at,
     *                                     product, tenant_id)
     */
    public function publish(string $type, string $productId, ?string $tenantId, array $detail): void;

    /**
     * One event, to every product the tenant holds — for what concerns the
     * tenant as a whole. A membership is mirrored onto every product the
     * tenant holds (ADR-047), so a member joining is news to each of them.
     *
     * @param array<string, mixed> $detail
     */
    public function publishForTenant(string $type, string $tenantId, array $detail): void;

    /**
     * One event, to every product that has an address — for what concerns
     * no product in particular, such as a person erased (§26).
     *
     * @param array<string, mixed> $detail
     */
    public function publishToAll(string $type, array $detail): void;
}
