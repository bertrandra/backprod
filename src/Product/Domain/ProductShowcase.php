<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * The story a product tells on its own page (2026-09-24,
 * `docs/home-showcase-spec.md`).
 *
 * A port of its own rather than more methods on {@see ProductRegistry},
 * which every screen on the platform depends on to ask what a product *is*.
 * The same reasoning that keeps {@see ProductCapabilities} apart: a reader
 * of one must not thereby be a writer of the other.
 *
 * **Read twice, by two different callers**, and that is the whole shape of
 * this interface:
 *
 * - a **stranger** reads one published product's story, resolved into their
 *   language, by the product's *code* — they have no session, no product
 *   context and no permissions, so there is no id to name and nothing to
 *   resolve one from;
 * - the **console** reads every block of one product with every language
 *   beside it, because it is the only place that can finish a
 *   half-translated page and the only place where seeing a gap helps.
 */
interface ProductShowcase
{
    /**
     * Every block of a product, in order, with every translation.
     *
     * For the console. Unpublished included — that is what a draft is.
     *
     * @return list<ShowcaseBlock>
     */
    public function blocksOf(string $productId): array;

    /**
     * One published product's story, by code, for somebody with no session.
     *
     * Null for a product nobody has published, a code nobody has, and a
     * product that does not exist — indistinguishable on purpose, the same
     * non-answer `getPublicOffers` gives, so this cannot be used to
     * enumerate what a deployment hosts (ADR-041).
     *
     * A **retired** product still answers: retiring stops the selling, not
     * the record (§11.3). What it stops is the prices band having anything
     * to show, which the page says in words.
     */
    public function published(string $code): ?PublishedShowcase;

    /**
     * Replaces the story, wholly.
     *
     * The console sends what the page says; a block it left out is one it
     * removed. Merging would make deleting a block impossible without a
     * route whose only purpose was deletion — the same reasoning as a
     * translation set (`docs/translatable-fields-spec.md`).
     *
     * One transaction, because a page half written is a page with a
     * sentence missing from the middle.
     *
     * @param list<ShowcaseBlock> $blocks
     *
     * @return list<ShowcaseBlock> what the product now says
     */
    public function replace(string $productId, array $blocks): array;

    /**
     * Publishes the story, or takes it back to a draft.
     *
     * Refused while there is no headline with an English sentence in it:
     * one language is enough to publish and English is that language
     * (§11.2), but *nothing* is not a story.
     *
     * @throws \App\Shared\Exceptions\ConflictException SHOWCASE_INCOMPLETE
     */
    public function publish(string $productId, bool $published): ?PublishedShowcase;
}
