<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\Row;
use App\Staff\Domain\TranslatableText;
use App\Staff\Domain\TranslationDesk;
use Doctrine\DBAL\Connection;

/**
 * The translation desk, in PostgreSQL: two queries, whatever the number of
 * rows (2026-09-26).
 *
 * One per translated table, each joining its side table in the same statement
 * — `LEFT JOIN`, because a sentence with no translation at all is the most
 * interesting row on this screen and an inner join would hide exactly those.
 *
 * A feature carries two sentences and an offer one, so the rows are exploded
 * per *field* rather than per row. That is what lets the screen search and
 * count sentences instead of records: "twelve missing in Italian" means twelve
 * boxes to fill, and a feature whose name is translated and whose description
 * is not counts once, not zero.
 */
final class PostgresTranslationDesk implements TranslationDesk
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function everything(): array
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
