<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Product\Domain\ProductDirectory;
use App\Product\Domain\ProductShowcase;
use App\Product\Domain\PublishedShowcase;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;

/**
 * The story a product tells, written by the platform (2026-09-24,
 * `docs/home-showcase-spec.md` §11.1).
 *
 * **The platform's own staff, never a tenant.** A page written by one
 * customer would be read by every other customer of the same product, which
 * is why this is a `/staff/*` surface under `staff.products.manage` and
 * there is no tenant-side equivalent to lend it to.
 */
final class ShowcaseDesk
{
    public function __construct(
        private readonly ProductShowcase $showcase,
        private readonly ProductDirectory $products,
    ) {
    }

    /**
     * Every block of one product, with every language beside it.
     *
     * Drafts included — that is what a draft is — because this is the only
     * screen that can finish a half-translated page.
     *
     * @return array{product: \App\Product\Domain\Product, blocks: list<ShowcaseBlock>, sections: list<string>, headings: array<string, \App\Product\Domain\ShowcaseBandHeading>, published_at: ?\DateTimeImmutable}
     */
    public function story(string $productId): array
    {
        $product = $this->product($productId);

        return [
            'product' => $product,
            'blocks' => $this->showcase->blocksOf($productId),
            // A band the map does not name has never been retitled, which
            // the page reads as its compiled default — never an empty title.
            'headings' => $this->showcase->headingsOf($productId),
            // Always a full order, never "unset": the console draws one row
            // per section and a screen that had to know what an absent order
            // meant would be a second place the default lives.
            'sections' => $this->showcase->sectionsOf($productId),
            'published_at' => $this->publishedAt($product->code),
        ];
    }

    /**
     * Replaces the story, and says which product it belongs to.
     *
     * The code comes back with the blocks because the presenter needs it
     * to build a picture's address, and this method has already looked the
     * product up to check it exists — asking twice would be two reads for
     * one fact.
     *
     * @param list<ShowcaseBlock>                                       $blocks
     * @param list<string>|null                                         $sections the order to read the page in, or null to leave it alone
     * @param array<string, \App\Product\Domain\ShowcaseBandHeading>|null $headings the bands' own headings, or null to leave them
     *
     * @return array{code: string, blocks: list<ShowcaseBlock>, sections: list<string>, headings: array<string, \App\Product\Domain\ShowcaseBandHeading>}
     */
    public function write(StaffIdentity $staff, string $productId, array $blocks, ?array $sections = null, ?array $headings = null): array
    {
        $product = $this->product($productId);
        $written = $this->showcase->replace($productId, $blocks, $sections, $headings);

        return [
            'code' => $product->code,
            'blocks' => $written,
            // Read back rather than echoed: where the request said nothing,
            // the answer still has to carry the order the page actually has.
            'sections' => $this->showcase->sectionsOf($productId),
            'headings' => $this->showcase->headingsOf($productId),
        ];
    }

    public function publish(StaffIdentity $staff, string $productId, bool $published): ?PublishedShowcase
    {
        $this->product($productId);

        return $this->showcase->publish($productId, $published);
    }

    private function publishedAt(string $code): ?\DateTimeImmutable
    {
        return $this->showcase->published($code)?->publishedAt;
    }

    /**
     * The product this story belongs to, by id.
     *
     * Filtered from the whole list rather than asked for by id, because
     * {@see ProductDirectory} has no such read and inventing one for this
     * would be a port method with a single caller. The list is unpaged on
     * purpose — a platform hosts a handful of products, which is the same
     * reasoning that made it unpaged in the first place.
     */
    private function product(string $productId): \App\Product\Domain\Product
    {
        foreach ($this->products->all() as $candidate) {
            if ($candidate->id === $productId) {
                return $candidate;
            }
        }

        throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
    }
}
