<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * Every product on the platform, and the acts that change that set.
 *
 * A third port beside {@see ProductRepository} and {@see ProductRegistry}, and
 * separate from both for the reason that made this screen necessary:
 *
 *   - `ProductRepository` answers the one question every request asks —
 *     "which product is this code?" — and is deliberately narrow;
 *   - `ProductRegistry` answers "which products may *this person* reach", by
 *     membership, and is therefore blind to everything they are not a member
 *     of. A platform role grants no membership (non-negotiable #22), so an
 *     administrator asking it about the platform is asking the wrong port.
 *
 * This one answers "what exists", with no caller in the question. It is
 * reachable only behind `staff.products.manage`, which is what keeps "which
 * products a deployment hosts is commercial information" true for everybody
 * else.
 *
 * Inactive products are included. They are the ones an administrator most
 * needs to see — a product switched off is still a product with tenants,
 * subscriptions and invoices hanging from it, and a list that hid them would
 * make it look as though those had gone too.
 */
interface ProductDirectory
{
    /**
     * Every product, newest first, active or not.
     *
     * Unpaged, like the staff roster: a platform hosts a handful of products,
     * and a cursor over five rows is machinery nobody needs.
     *
     * @return list<Product>
     */
    public function all(): array;

    /**
     * A new product.
     *
     * The code is what every client sends as `X-Product` and what the public
     * storefront takes as `?product=`, so it is an identifier and this is the
     * only moment it is chosen.
     *
     * @throws \App\Shared\Exceptions\ConflictException if the code is taken
     */
    public function create(string $code, string $name): Product;

    /**
     * Renames a product, switches it off, or both.
     *
     * The code is never touched. It is how documents, links and configuration
     * refer to this product — an identifier that can change is not an
     * identifier, which is the same rule offers already follow.
     *
     * Null for either field means "leave it", so a rename does not have to
     * restate the active flag and risk flipping it by omission.
     */
    /**
     * @param bool        $setAppUrl whether `$appUrl` is to be written — null is a value (clear it), so absence needs its own flag
     * @param string|null $appUrl    the address, or null to clear it
     */
    public function update(string $productId, ?string $name, ?bool $active, bool $setAppUrl = false, ?string $appUrl = null): ?Product;
}
