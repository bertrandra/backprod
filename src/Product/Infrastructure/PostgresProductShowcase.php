<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ProductShowcase;
use App\Product\Domain\PublishedShowcase;
use App\Product\Domain\ShowcaseBlock;
use App\Shared\Database\Row;
use App\Shared\Exceptions\ConflictException;
use Doctrine\DBAL\Connection;

final class PostgresProductShowcase implements ProductShowcase
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function blocksOf(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, block, position, content, asset_id
                  FROM product_showcase
                 WHERE product_id = :product
                 ORDER BY block, position
                SQL,
            ['product' => $productId],
        );

        return $this->toBlocks($rows);
    }

    public function published(string $code): ?PublishedShowcase
    {
        $product = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, code, name, active, showcase_published_at
                  FROM products
                 WHERE code = :code AND showcase_published_at IS NOT NULL
                SQL,
            ['code' => $code],
        );

        // One answer for "no such product", "never published" and — through
        // the caller — "nothing at all": a public endpoint that told them
        // apart would be a way to enumerate what a deployment hosts.
        // Deliberately **not** filtered on `active`: a retired product keeps
        // its page (home-showcase-spec §11.3).
        if ($product === false) {
            return null;
        }

        return new PublishedShowcase(
            Row::string($product, 'id'),
            Row::string($product, 'code'),
            Row::string($product, 'name'),
            Row::boolean($product, 'active'),
            $this->blocksOf(Row::string($product, 'id')),
            Row::nullableTimestamp($product, 'showcase_published_at'),
        );
    }

    public function replace(string $productId, array $blocks): array
    {
        return $this->connection->transactional(function () use ($productId, $blocks): array {
            // Deleted first, so a block the console left out is one it
            // removed. The translations go with it by cascade, which is what
            // makes removing a band a single act rather than two that can
            // half-fail.
            $this->connection->executeStatement(
                'DELETE FROM product_showcase WHERE product_id = :product',
                ['product' => $productId],
            );

            foreach ($blocks as $block) {
                $id = $this->connection->fetchOne(
                    <<<'SQL'
                        INSERT INTO product_showcase (product_id, block, position, content, asset_id)
                        VALUES (:product, :block, :position, CAST(:content AS jsonb), :asset)
                        RETURNING id
                        SQL,
                    [
                        'product' => $productId,
                        'block' => $block->block,
                        'position' => $block->position,
                        'content' => self::json($block->content),
                        'asset' => $block->assetId,
                    ],
                );

                if (!is_string($id)) {
                    throw new ConflictException('SHOWCASE_NOT_WRITTEN', 'The story could not be written.');
                }

                foreach ($block->translations as $locale => $content) {
                    if ($content === []) {
                        continue;
                    }

                    $this->connection->executeStatement(
                        <<<'SQL'
                            INSERT INTO product_showcase_translations (block_id, locale, content)
                            VALUES (:block, :locale, CAST(:content AS jsonb))
                            SQL,
                        ['block' => $id, 'locale' => $locale, 'content' => self::json($content)],
                    );
                }
            }

            return $this->blocksOf($productId);
        });
    }

    public function publish(string $productId, bool $published): ?PublishedShowcase
    {
        if ($published && !$this->hasAnEnglishHeadline($productId)) {
            // One language is enough and English is that language (§11.2) —
            // but *nothing* is not a story, and a published page with no
            // headline is a shop window with the sign taken down.
            throw new ConflictException(
                'SHOWCASE_INCOMPLETE',
                'A story needs a headline in English before it can be published.',
                ['requirement' => 'a HEADLINE block whose headline is not empty'],
            );
        }

        $code = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE products
                   SET showcase_published_at = CASE WHEN :published THEN now() ELSE NULL END,
                       updated_at = now()
                 WHERE id = :id
                RETURNING code
                SQL,
            ['id' => $productId, 'published' => $published ? 'true' : 'false'],
        );

        if (!is_string($code)) {
            return null;
        }

        return $published ? $this->published($code) : null;
    }

    private function hasAnEnglishHeadline(string $productId): bool
    {
        // Asked of the stored row rather than of what was just sent: the
        // two routes are separate acts, and publishing has to be true of
        // what is actually there.
        $found = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                  FROM product_showcase
                 WHERE product_id = :product
                   AND block = 'HEADLINE'
                   AND btrim(coalesce(content ->> 'headline', '')) <> ''
                SQL,
            ['product' => $productId],
        );

        return $found !== false && $found !== null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ShowcaseBlock>
     */
    private function toBlocks(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $translations = $this->translationsOf(
            array_map(static fn (array $row): string => Row::string($row, 'id'), $rows),
        );

        return array_map(
            static fn (array $row): ShowcaseBlock => new ShowcaseBlock(
                Row::string($row, 'id'),
                Row::string($row, 'block'),
                Row::integer($row, 'position'),
                self::decode(Row::nullableString($row, 'content')),
                $translations[Row::string($row, 'id')] ?? [],
                Row::nullableString($row, 'asset_id'),
            ),
            $rows,
        );
    }

    /**
     * Every language of every block, in one query.
     *
     * A page is a handful of blocks and four languages, so the whole set
     * comes back at once and the caller picks — the same shape the
     * catalogue's translations use, and for the same reason: a read per
     * block would be an N+1 paid on the page a stranger sees first.
     *
     * @param list<string> $blockIds
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function translationsOf(array $blockIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT block_id, locale, content
                  FROM product_showcase_translations
                 WHERE block_id = ANY(CAST(:ids AS uuid[]))
                 ORDER BY block_id, locale
                SQL,
            ['ids' => '{' . implode(',', $blockIds) . '}'],
        );

        $byBlock = [];

        foreach ($rows as $row) {
            $byBlock[Row::string($row, 'block_id')][Row::string($row, 'locale')] =
                self::decode(Row::nullableString($row, 'content'));
        }

        return $byBlock;
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function json(array $content): string
    {
        // `JSON_THROW_ON_ERROR`, because a silent `false` would be stored as
        // the string "false" and read back as a block with no fields.
        return json_encode($content, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        // A malformed row is an empty block rather than an exception: the
        // page it belongs to is read by strangers, and one bad caption must
        // not be a 500 on a shop window (spec §6).
        if (!is_array($decoded)) {
            return [];
        }

        $content = [];

        // Narrowed one key at a time rather than asserted: the column is
        // `jsonb` and `CHECK (jsonb_typeof(content) = 'object')` makes the
        // keys strings, but a decoder that trusted a constraint would be
        // trusting it from the wrong side of the wire.
        foreach ($decoded as $field => $value) {
            if (is_string($field)) {
                $content[$field] = $value;
            }
        }

        return $content;
    }
}
