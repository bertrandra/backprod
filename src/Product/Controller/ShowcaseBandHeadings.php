<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ShowcaseBandHeading;
use App\Product\Domain\ShowcaseSections;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Validation\Locale;
use stdClass;

/**
 * The headings a request sends, checked (2026-09-28).
 *
 * The companion of {@see ShowcaseBlocks}, guarding the other half of a
 * story: that one checks the shape of a band's *rows*, this one the shape of
 * the band's own eyebrow, title and lede.
 *
 * **Every section may have one, `PRICING` included** — that is the whole
 * point of the table behind it. What `PRICING` still may not have is a row,
 * so there is no field here that could hold an amount: a heading is a
 * promise about the prices, never one of them (§9).
 *
 * **Absent means "leave the headings alone"**, the same rule the order
 * follows, and for the same reason: the translation desk writes one sentence
 * through the operation that owns the story and carries no headings, so a
 * request without them that cleared them would erase a page's titles by
 * translating a step.
 *
 * Every field is plain text and optional; blank removes it, which is how a
 * band goes back to its compiled default.
 */
final class ShowcaseBandHeadings
{
    /**
     * What a heading carries, whichever band it belongs to.
     *
     * One shape for every section rather than a table per kind, because a
     * heading genuinely is the same three things everywhere — the eyebrow
     * above, the title, the sentence under it. `ShowcaseBlocks` needs a
     * table per kind because a headline and a question really are different;
     * this does not, and inventing the difference would be a second place to
     * keep in step for nothing.
     *
     * @var list<string>
     */
    private const FIELDS = ['eyebrow', 'title', 'lede'];

    /** Shorter than a band's prose: these are headings, not paragraphs. */
    private const LONGEST = 400;

    /**
     * @return array<string, ShowcaseBandHeading>|null keyed by section, or null where the request said nothing
     */
    public static function of(JsonBody $body, string $field): ?array
    {
        if (!$body->has($field)) {
            return null;
        }

        $raw = $body->requiredObject($field);
        $headings = [];

        foreach (get_object_vars($raw) as $block => $heading) {
            if (!is_string($block) || !in_array($block, ShowcaseSections::DEFAULT_ORDER, true)) {
                throw self::invalid(
                    is_string($block) ? $block : (string) $block,
                    '',
                    'one of ' . implode(', ', ShowcaseSections::DEFAULT_ORDER),
                );
            }

            if (!$heading instanceof stdClass) {
                throw self::invalid($block, '', 'an object of this band’s heading fields');
            }

            $content = self::content($heading->content ?? null, $block, 'content');
            $translations = self::translations($heading->translations ?? null, $block);

            // A heading with nothing in it, in no language, is one removed:
            // the band goes back to the words its component was written
            // with, which is a state the operator has to be able to reach.
            if ($content === [] && $translations === []) {
                continue;
            }

            $headings[$block] = new ShowcaseBandHeading($block, $content, $translations);
        }

        return $headings;
    }

    /**
     * @return array<string, string>
     */
    private static function content(mixed $raw, string $block, string $where): array
    {
        if ($raw === null) {
            return [];
        }

        if (!$raw instanceof stdClass) {
            throw self::invalid($block, $where, 'an object of this band’s heading fields');
        }

        $content = [];

        foreach (get_object_vars($raw) as $field => $value) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                // Refused rather than dropped, the same rule a band's fields
                // follow: a field this endpoint stored and no band rendered
                // would be an operator's afternoon spent writing into a hole.
                throw self::invalid($block, $where . '.' . (is_string($field) ? $field : (string) $field), 'one of ' . implode(', ', self::FIELDS));
            }

            if (!is_string($value) || mb_strlen($value) > self::LONGEST) {
                throw self::invalid($block, $where . '.' . $field, sprintf('text of at most %d characters', self::LONGEST));
            }

            if (trim($value) !== '') {
                $content[$field] = trim($value);
            }
        }

        return $content;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function translations(mixed $raw, string $block): array
    {
        if ($raw === null) {
            return [];
        }

        if (!$raw instanceof stdClass) {
            throw self::invalid($block, 'translations', 'an object keyed by language');
        }

        $translations = [];

        foreach (get_object_vars($raw) as $locale => $content) {
            // `is_string` as well, because PHP narrows a numeric-looking
            // object key to `int` — the same trap `ShowcaseBlocks` names.
            if (!is_string($locale) || !Locale::isTranslatable($locale)) {
                throw self::invalid(
                    $block,
                    'translations.' . (is_string($locale) ? $locale : (string) $locale),
                    'one of ' . implode(', ', Locale::TRANSLATABLE),
                );
            }

            $written = self::content($content, $block, 'translations.' . $locale);

            if ($written !== []) {
                $translations[$locale] = $written;
            }
        }

        return $translations;
    }

    private static function invalid(string $block, string $where, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            [
                'field' => rtrim(sprintf('bands.%s.%s', $block, $where), '.'),
                'requirement' => $requirement,
            ],
        );
    }
}
