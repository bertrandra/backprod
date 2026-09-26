<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\Row;
use App\Staff\Domain\TranslatableText;
use App\Staff\Domain\TranslationDesk;
use Doctrine\DBAL\Connection;

/**
 * The translation desk, in PostgreSQL: three queries, whatever the number of
 * rows (2026-09-26).
 *
 * One per translated table, each joining its side table in the same statement
 * — `LEFT JOIN`, because a sentence with no translation at all is the most
 * interesting row on this screen and an inner join would hide exactly those.
 *
 * A feature carries two sentences, an offer one and a band as many as it has
 * fields, so the rows are exploded per *field* rather than per row. That is
 * what lets the screen search and count sentences instead of records: "twelve
 * missing in Italian" means twelve boxes to fill, and a feature whose name is
 * translated and whose description is not counts once, not zero.
 */
final class PostgresTranslationDesk implements TranslationDesk
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function catalogue(): array
    {
        return array_merge($this->features(), $this->offers());
    }

    /**
     * @return list<TranslatableText>
     */
    private function features(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT f.id, f.code, f.name, f.description,
                       t.locale, t.name AS translated_name, t.description AS translated_description
                  FROM features f
                  LEFT JOIN feature_translations t ON t.feature_id = f.id
                 ORDER BY f.code, t.locale
                SQL,
        );

        /** @var array<string, array{code: string, name: string, description: ?string, byLocale: array<string, array{name: ?string, description: ?string}>}> $byFeature */
        $byFeature = [];

        foreach ($rows as $row) {
            $id = Row::string($row, 'id');

            $byFeature[$id] ??= [
                'code' => Row::string($row, 'code'),
                'name' => Row::string($row, 'name'),
                'description' => Row::nullableString($row, 'description'),
                'byLocale' => [],
            ];

            $locale = Row::nullableString($row, 'locale');

            if ($locale !== null) {
                $byFeature[$id]['byLocale'][$locale] = [
                    'name' => Row::nullableString($row, 'translated_name'),
                    'description' => Row::nullableString($row, 'translated_description'),
                ];
            }
        }

        $texts = [];

        foreach ($byFeature as $id => $feature) {
            $texts[] = new TranslatableText(
                TranslatableText::FEATURE,
                $id,
                $feature['code'],
                'name',
                $feature['name'],
                self::only($feature['byLocale'], 'name'),
            );

            $description = self::only($feature['byLocale'], 'description');

            // A description that was never written is not a sentence waiting
            // for a translator — it is a field the operator left empty, and
            // putting it on the desk would count a translation nobody owes.
            //
            // Unless a language still says something. `renameFeature` clears
            // the English with `description: null` and leaves the translations
            // where they are, so that row exists; dropping it here would both
            // hide the inconsistency and hand the screen a translation set with
            // the French missing, which the next write would delete.
            if (($feature['description'] ?? '') !== '' || $description !== []) {
                $texts[] = new TranslatableText(
                    TranslatableText::FEATURE,
                    $id,
                    $feature['code'],
                    'description',
                    (string) $feature['description'],
                    $description,
                );
            }
        }

        return $texts;
    }

    /**
     * @return list<TranslatableText>
     */
    private function offers(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.code, o.name, p.code AS product_code,
                       t.locale, t.name AS translated_name
                  FROM offers o
                  JOIN products p ON p.id = o.product_id
                  LEFT JOIN offer_translations t ON t.offer_id = o.id
                 ORDER BY p.code, o.code, t.locale
                SQL,
        );

        /** @var array<string, array{code: string, name: string, product: string, byLocale: array<string, string>}> $byOffer */
        $byOffer = [];

        foreach ($rows as $row) {
            $id = Row::string($row, 'id');

            $byOffer[$id] ??= [
                'code' => Row::string($row, 'code'),
                'name' => Row::string($row, 'name'),
                'product' => Row::string($row, 'product_code'),
                'byLocale' => [],
            ];

            $locale = Row::nullableString($row, 'locale');
            $translated = Row::nullableString($row, 'translated_name');

            if ($locale !== null && $translated !== null && $translated !== '') {
                $byOffer[$id]['byLocale'][$locale] = $translated;
            }
        }

        $texts = [];

        foreach ($byOffer as $id => $offer) {
            $texts[] = new TranslatableText(
                TranslatableText::OFFER,
                $id,
                $offer['code'],
                'name',
                $offer['name'],
                $offer['byLocale'],
                $offer['product'],
            );
        }

        return $texts;
    }

    /**
     * Every product's story, a sentence per string field of every band
     * (2026-09-26).
     *
     * One query, like the two above, and for the same reason: a page is a
     * handful of bands and four languages, and a read per band would be an
     * N+1 paid on a screen whose whole purpose is to count the whole set.
     *
     * **The shapes are not written down here, and must not be.** A band's
     * fields differ by kind and the domain refuses to know all five
     * ({@see \App\Product\Domain\ShowcaseBlock}) so that adding a sixth is
     * four edits and none of them in a domain object. The desk keeps that
     * promise by deriving its sentences from the data: whatever string the
     * object holds is a sentence, whatever it is called.
     *
     * **The sentence is located by `(block, position, field)` and never by
     * the block's id.** `ProductShowcase::replace` deletes every row and
     * inserts them again, so a block's id changes on every save; the
     * `product_showcase_ordered` unique index makes the band and its
     * position a key that does not.
     *
     * @return list<TranslatableText>
     */
    public function showcase(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.id AS product_id, p.code AS product_code,
                       s.block, s.position, s.content,
                       t.locale, t.content AS translated
                  FROM product_showcase s
                  JOIN products p ON p.id = s.product_id
                  LEFT JOIN product_showcase_translations t ON t.block_id = s.id
                 ORDER BY p.code, s.block, s.position, t.locale
                SQL,
        );

        /** @var array<string, array{product: string, code: string, block: string, position: int, content: array<string, string>, byLocale: array<string, array<string, string>>}> $byBlock */
        $byBlock = [];

        foreach ($rows as $row) {
            $product = Row::string($row, 'product_id');
            $block = Row::string($row, 'block');
            $position = Row::integer($row, 'position');
            $at = $product . ':' . $block . ':' . $position;

            $byBlock[$at] ??= [
                'product' => $product,
                'code' => Row::string($row, 'product_code'),
                'block' => $block,
                'position' => $position,
                'content' => self::sentencesIn(Row::nullableString($row, 'content')),
                'byLocale' => [],
            ];

            $locale = Row::nullableString($row, 'locale');

            if ($locale !== null) {
                $byBlock[$at]['byLocale'][$locale] = self::sentencesIn(Row::nullableString($row, 'translated'));
            }
        }

        $texts = [];

        foreach ($byBlock as $band) {
            foreach (self::fieldsOf($band['content'], $band['byLocale']) as $field) {
                $translations = [];

                foreach ($band['byLocale'] as $locale => $written) {
                    if (isset($written[$field])) {
                        $translations[$locale] = $written[$field];
                    }
                }

                $texts[] = new TranslatableText(
                    TranslatableText::SHOWCASE,
                    // The **product**, because the operation that writes
                    // these replaces the whole story: there is no route that
                    // takes a band's id, so putting one here would be an
                    // identifier nothing accepts.
                    $band['product'],
                    $band['code'],
                    sprintf('%s.%d.%s', $band['block'], $band['position'], $field),
                    // Empty where a language says something the English no
                    // longer does. `contentIn` adds a translated field to the
                    // resolved content whether or not the English has one, so
                    // that sentence is on a reader's screen and belongs on
                    // this one — the same rule as a feature description the
                    // operator cleared.
                    $band['content'][$field] ?? '',
                    $translations,
                    // No product badge: the story *is* the product's, `code`
                    // already names it, and saying it twice would put the
                    // same word in a card's title and beside it.
                    null,
                );
            }
        }

        return $texts;
    }

    /**
     * Which fields of one band are sentences, English and translations
     * together, in an order that does not move.
     *
     * **Sorted by name**, which is nobody's idea of a reading order —
     * `answer` before `question` — and is the only order available: the
     * natural one is the band's field list, and knowing that list is exactly
     * what this class must not do. `jsonb` does not keep the order they were
     * written in either (it sorts keys by length, then bytes), so there is no
     * author's order left to preserve.
     *
     * @param array<string, string>                $content
     * @param array<string, array<string, string>> $byLocale
     *
     * @return list<string>
     */
    private static function fieldsOf(array $content, array $byLocale): array
    {
        $fields = $content;

        foreach ($byLocale as $written) {
            $fields += $written;
        }

        $names = array_keys($fields);
        sort($names);

        return $names;
    }

    /**
     * The sentences in one stored JSON object.
     *
     * **A value that is not a string is not a sentence**, and is dropped
     * rather than listed. Two reasons, and the first is the one that decides
     * it: `ShowcaseBlock::contentIn` substitutes a translated field only when
     * it is a non-blank string, so a translation of a list of steps is a box
     * whose contents no reader would ever see — the desk would be counting
     * work that changes nothing. The second is that a band holding a
     * structure holds it because the *band* means something by it, and its
     * shape is that component's business; a translator is owed the sentences
     * inside it, which is a thing to arrange by storing them as fields, not
     * by handing them a JSON fragment in a text box.
     *
     * A blank string goes the same way, for the reason the catalogue's
     * blanks do: the writer already drops them, so one that survived says
     * nothing and is owed no translation.
     *
     * @return array<string, string>
     */
    private static function sentencesIn(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        // A malformed row is a band with nothing to translate rather than an
        // exception — the same answer `PostgresProductShowcase` gives it, and
        // for a stronger reason here: one unreadable row must not take the
        // whole desk down.
        if (!is_array($decoded)) {
            return [];
        }

        $sentences = [];

        foreach ($decoded as $field => $value) {
            if (is_string($field) && is_string($value) && trim($value) !== '') {
                $sentences[$field] = $value;
            }
        }

        return $sentences;
    }

    /**
     * One field's translations out of the pair a feature row carries.
     *
     * An empty string is dropped rather than kept: the table refuses a row
     * that says nothing in either field, so a present-but-empty value means
     * "the other field was translated and this one was not", which is a
     * missing translation and must count as one.
     *
     * @param array<string, array{name: ?string, description: ?string}> $byLocale
     *
     * @return array<string, string>
     */
    private static function only(array $byLocale, string $field): array
    {
        $found = [];

        foreach ($byLocale as $locale => $values) {
            $value = $values[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $found[$locale] = $value;
            }
        }

        return $found;
    }
}
