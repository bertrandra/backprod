<?php

declare(strict_types=1);

namespace App\Product\Domain;

use DateTimeImmutable;

/**
 * A product's story as a reader gets it (2026-09-24).
 *
 * The product's own facts beside the blocks, because the page needs both
 * and a stranger cannot ask for the product separately: they have no
 * session, so `GET /products` — which resolves through membership — answers
 * them nothing.
 *
 * `$active` is here for one reason, and it is the reason the operator's
 * decision needs (home-showcase-spec §11.3): a retired product keeps its
 * page, and the prices band has to say *No longer sold* rather than showing
 * an empty band or a Buy that leads to a refusal. The member's shell cannot
 * tell — `GET /products` carries no `active` — so this is where the page
 * learns it.
 */
final class PublishedShowcase
{
    /**
     * @param list<ShowcaseBlock> $blocks
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $active,
        public readonly array $blocks,
        public readonly ?DateTimeImmutable $publishedAt = null,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }
}
