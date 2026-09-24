<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Product\Domain\ProductDirectory;
use App\Product\Domain\ProductShowcase;
use App\Product\Domain\PublishedShowcase;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

/**
 * The story a product tells, written by the platform (2026-09-24,
 * `docs/home-showcase-spec.md` §11.1).
 *
 * **The platform's own staff, never a tenant.** A page written by one
 * customer would be read by every other customer of the same product, which
 * is why this is a `/staff/*` surface under `staff.products.manage` and
 * there is no tenant-side equivalent to lend it to.
 *
 * **Writes are recorded, reads are not**, the same rule as the catalogue
 * and the feature list: non-negotiable #21 traces staff crossing into a
 * *tenant's* data, and a product's own shop window is nobody's tenant. What
 * is recorded is every act that changes what a stranger reads, because
 * "who published this?" is a question somebody eventually asks.
 */
final class ShowcaseDesk
{
    public function __construct(
        private readonly ProductShowcase $showcase,
        private readonly ProductDirectory $products,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * Every block of one product, with every language beside it.
     *
     * Drafts included — that is what a draft is — because this is the only
     * screen that can finish a half-translated page.
     *
     * @return array{product: \App\Product\Domain\Product, blocks: list<ShowcaseBlock>, published_at: ?\DateTimeImmutable}
     */
    public function story(string $productId): array
    {
        $product = $this->product($productId);

        return [
            'product' => $product,
            'blocks' => $this->showcase->blocksOf($productId),
            'published_at' => $this->publishedAt($product->code),
        ];
    }

    /**
     * @param list<ShowcaseBlock> $blocks
     *
     * @return list<ShowcaseBlock>
     */
    public function write(StaffIdentity $staff, string $productId, array $blocks): array
    {
        $product = $this->product($productId);
        $written = $this->showcase->replace($productId, $blocks);

        // The bands it now has and the languages they say something in —
        // not what they say. A trail answers "who changed this, and roughly
        // what", and four paragraphs of marketing copy in an audit row is
        // neither readable nor anybody's business later.
        $this->record($staff, $product->id, 'WRITE', [
            'blocks' => array_map(static fn (ShowcaseBlock $block): string => $block->block, $written),
            'translated' => self::languages($written),
        ]);

        return $written;
    }

    public function publish(StaffIdentity $staff, string $productId, bool $published): ?PublishedShowcase
    {
        $product = $this->product($productId);
        $answer = $this->showcase->publish($productId, $published);

        // Publishing and withdrawing are different acts in the trail. "Who
        // put this in front of strangers, and when did it come down" is one
        // question with two answers, and a column of WRITE rows holds
        // neither.
        $this->record($staff, $product->id, $published ? 'PUBLISH' : 'WITHDRAW', []);

        return $answer;
    }

    private function publishedAt(string $code): ?\DateTimeImmutable
    {
        return $this->showcase->published($code)?->publishedAt;
    }

    /**
     * @param list<ShowcaseBlock> $blocks
     *
     * @return list<string>
     */
    private static function languages(array $blocks): array
    {
        $locales = [];

        foreach ($blocks as $block) {
            foreach (array_keys($block->translations) as $locale) {
                $locales[$locale] = true;
            }
        }

        $written = array_keys($locales);
        sort($written);

        return $written;
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

    /**
     * @param array<string, mixed> $detail
     */
    private function record(StaffIdentity $staff, string $productId, string $action, array $detail): void
    {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // No tenant: a product's shop window is the platform's own, and
            // naming a customer here would invent one this decision was not
            // about.
            null,
            $productId,
            $action,
            'showcase',
            $productId,
            StaffPermission::PRODUCTS_MANAGE,
            $detail,
        ));
    }
}
