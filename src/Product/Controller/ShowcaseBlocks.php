<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ShowcaseBlock;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Validation\Locale;
use stdClass;

/**
 * The story as a request sends it, checked (2026-09-24).
 *
 * In the Product module's controller layer and not in `App\Shared\Http`,
 * where it started: that namespace is transport-only and may not know a
 * module's domain. `deptrac` said so and was right — a shape arriving from
 * outside is this module's business, not the kernel's.
 *
 * **This is where a band's shape is guarded**, and the only place it is.
 * The domain deliberately does not know that a headline has a headline and
 * a question has a question: it would need changing every time a band was
 * added, which is exactly what `docs/home-showcase-spec.md` §6 promises
 * does not happen.
 *
 * What that costs, said plainly: adding a band means adding its fields
 * here. That is the fifth edit the specification's "four edits" does not
 * count, and it is the right place for it — this is the file that decides
 * what a stranger's browser may be sent.
 *
 * **Every field is plain text.** No markup is accepted and none is
 * rendered: a rich-text field would be an XSS surface reachable by anybody
 * with `staff.products.manage` and read by everybody with a browser (§9).
 */
final class ShowcaseBlocks
{
    /** A page is a handful of bands; a request with more is a mistake. */
    private const MAXIMUM = 40;

    /**
     * The fields each band carries, and which of them must say something.
     *
     * @var array<string, array{required: list<string>, optional: list<string>}>
     */
    private const FIELDS = [
        ShowcaseBlock::HEADLINE => ['required' => ['headline'], 'optional' => ['subline']],
        ShowcaseBlock::STEPS => ['required' => ['title'], 'optional' => ['body']],
        ShowcaseBlock::USE_CASE => ['required' => ['who'], 'optional' => ['before', 'after']],
        ShowcaseBlock::PROOF => ['required' => ['caption'], 'optional' => []],
        ShowcaseBlock::QUESTION => ['required' => ['question', 'answer'], 'optional' => []],
    ];

    /** Long enough for a paragraph, short enough that nobody pastes a book. */
    private const LONGEST = 2_000;

    /**
     * @return list<ShowcaseBlock>
     */
    public static function of(JsonBody $body, string $field): array
    {
        $blocks = [];
        $seen = [];

        foreach ($body->optionalObjectList($field, self::MAXIMUM) as $index => $raw) {
            $block = self::one($raw, $index);

            // Said here as well as by `product_showcase_one_headline`,
            // because a partial unique index refuses with a constraint's
            // name and somebody adding a second hero is owed the reason.
            if (in_array($block->block, ShowcaseBlock::SINGLETONS, true)) {
                if (isset($seen[$block->block])) {
                    throw self::invalid($index, 'block', sprintf('only one %s on a page', $block->block));
                }

                $seen[$block->block] = true;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    private static function one(stdClass $raw, int $index): ShowcaseBlock
    {
        $kind = $raw->block ?? null;

        if (!is_string($kind) || !in_array($kind, ShowcaseBlock::KINDS, true)) {
            // `PRICING` lands here, which is the point: it is a position in
            // the order and reads the catalogue, so a row for it would be a
            // row somebody could type a price into.
            throw self::invalid($index, 'block', 'one of ' . implode(', ', ShowcaseBlock::KINDS));
        }

        $position = $raw->position ?? 10;

        if (!is_int($position) || $position < 1) {
            throw self::invalid($index, 'position', 'a positive whole number');
        }

        return new ShowcaseBlock(
            // Ignored on the way in: the story is replaced wholly, so a
            // block's id is the database's to mint and never the client's
            // to choose.
            '',
            $kind,
            $position,
            self::content($raw->content ?? null, $kind, $index, true),
            self::translations($raw->translations ?? null, $kind, $index),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function content(mixed $raw, string $kind, int $index, bool $english): array
    {
        if (!$raw instanceof stdClass) {
            throw self::invalid($index, 'content', 'an object of this block’s fields');
        }

        $shape = self::FIELDS[$kind];
        $known = [...$shape['required'], ...$shape['optional']];
        $content = [];

        foreach ($known as $field) {
            $value = $raw->{$field} ?? null;

            if ($value === null) {
                continue;
            }

            if (!is_string($value) || mb_strlen($value) > self::LONGEST) {
                throw self::invalid($index, 'content.' . $field, sprintf('text of at most %d characters', self::LONGEST));
            }

            if (trim($value) !== '') {
                $content[$field] = trim($value);
            }
        }

        // Required in English only. A translation says what somebody has got
        // round to writing, and demanding a full set in every language is
        // what §11.2 refused: it would leave the shop window dark for a
        // product with something to say in one language.
        if ($english) {
            foreach ($shape['required'] as $field) {
                if (!isset($content[$field])) {
                    throw self::invalid($index, 'content.' . $field, 'a sentence, in English');
                }
            }
        }

        foreach (array_keys(get_object_vars($raw)) as $field) {
            if (!in_array($field, $known, true)) {
                // Refused rather than dropped: a field this endpoint stored
                // and no band rendered would be an operator's afternoon
                // spent writing into a hole.
                throw self::invalid($index, 'content.' . $field, sprintf('no such field on a %s', $kind));
            }
        }

        return $content;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function translations(mixed $raw, string $kind, int $index): array
    {
        if ($raw === null) {
            return [];
        }

        if (!$raw instanceof stdClass) {
            throw self::invalid($index, 'translations', 'an object keyed by language');
        }

        $translations = [];

        foreach (get_object_vars($raw) as $locale => $content) {
            // `is_string` as well as the locale check, because PHP narrows
            // a numeric-looking object key to `int`: `{"0": {...}}` would
            // otherwise make the returned map a list, and the difference
            // between `{}` and `[]` in the answer is what a client has to
            // guess the shape of.
            if (!is_string($locale) || !Locale::isTranslatable($locale)) {
                // English is refused by name, not merely unknown: it lives
                // on the block itself, and a second home for it would let
                // the two disagree.
                throw self::invalid(
                    $index,
                    'translations.' . (is_string($locale) ? $locale : (string) $locale),
                    'one of ' . implode(', ', Locale::TRANSLATABLE),
                );
            }

            $written = self::content($content, $kind, $index, false);

            if ($written !== []) {
                $translations[$locale] = $written;
            }
        }

        return $translations;
    }

    private static function invalid(int $index, string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => sprintf('blocks[%d].%s', $index, $field), 'requirement' => $requirement],
        );
    }
}
