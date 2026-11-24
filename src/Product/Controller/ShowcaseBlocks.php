<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ShowcaseBlock;
use App\Product\Domain\ShowcaseSections;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Validation\Locale;
use App\Shared\Validation\Uuid;
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
        ShowcaseBlock::HEADLINE => ['required' => ['headline'], 'optional' => ['subline', 'reassurance', 'alt']],
        ShowcaseBlock::PROBLEM => ['required' => ['title'], 'optional' => ['body']],
        ShowcaseBlock::QUOTE => ['required' => ['quote'], 'optional' => ['author']],
        ShowcaseBlock::STEPS => ['required' => ['title'], 'optional' => ['body', 'alt']],
        ShowcaseBlock::USE_CASE => ['required' => ['who'], 'optional' => ['before', 'after', 'alt']],
        ShowcaseBlock::PROOF => ['required' => ['caption'], 'optional' => ['alt']],
        // `DEMO` carries a caption, and then either an address to embed
        // or a picture — never both, which {@see self::oneOrTheOther()}
        // refuses. `alt` describes the picture when there is one.
        ShowcaseBlock::DEMO => ['required' => ['caption'], 'optional' => ['alt']],
        // `QUESTION` has no `alt` because it carries no picture (2026-09-28):
        // a field this endpoint stored and no band rendered is what the loop
        // below refuses on the way in, and it would be the same hole the
        // other way round — an operator writing a description of nothing.
        ShowcaseBlock::QUESTION => ['required' => ['question', 'answer'], 'optional' => []],
        // The four that sell (2026-11-20). `TRUST` is a name and, optionally,
        // a logo: the name is what a screen reader says for the picture, so
        // it carries no `alt` — two descriptions of one logo would be two
        // sentences that could disagree. `STATS` is a figure and what it
        // counts. `FEATURE` is what the product includes, with an icon from
        // the same closed set a problem draws from. `CTA` is the last word
        // on the page, and there is one of it.
        ShowcaseBlock::TRUST => ['required' => ['name'], 'optional' => []],
        ShowcaseBlock::STATS => ['required' => ['value', 'label'], 'optional' => ['detail']],
        ShowcaseBlock::FEATURE => ['required' => ['title'], 'optional' => ['body']],
        ShowcaseBlock::CTA => ['required' => ['title'], 'optional' => ['body']],
        // Two that show (2026-11-21). `TOUR` is a feature with its own
        // screenshot. `COMPARE` is one side of a before-and-after: what the
        // side is called, then — exactly as `DEMO` — an address to embed or a
        // picture, never both.
        ShowcaseBlock::TOUR => ['required' => ['title'], 'optional' => ['body', 'alt']],
        ShowcaseBlock::COMPARE => ['required' => ['label'], 'optional' => ['caption', 'alt']],
        ShowcaseBlock::ANNOUNCE => ['required' => ['text'], 'optional' => ['link_label']],
        ShowcaseBlock::VIDEO => ['required' => ['caption'], 'optional' => ['alt']],
        ShowcaseBlock::VERSUS => ['required' => ['criterion'], 'optional' => ['note']],
        ShowcaseBlock::HOTSPOT => ['required' => ['title'], 'optional' => ['body', 'alt']],
        ShowcaseBlock::CONTACT => ['required' => ['consent'], 'optional' => ['intro', 'thanks']],
        // Two beside the prices (2026-11-24). `REASSURE` is a line and what
        // it rests on. `REVIEW` is who said it and what they said; its mark
        // and its day are codes, below.
        ShowcaseBlock::REASSURE => ['required' => ['title'], 'optional' => ['detail']],
        ShowcaseBlock::REVIEW => ['required' => ['author', 'body'], 'optional' => ['role']],
    ];

    /**
     * Fields that are a **code and not a sentence** (2026-09-28).
     *
     * `icon` names one of a closed set the frontend draws. It is validated
     * against that set, and refused in a translation: an icon has no
     * French, and a locale that could hold one would be a second place the
     * band's shape is decided.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const CODES = [
        ShowcaseBlock::PROBLEM => ['icon' => ShowcaseBlock::ICONS],
        ShowcaseBlock::FEATURE => ['icon' => ShowcaseBlock::ICONS],
        ShowcaseBlock::DEMO => ['ratio' => ShowcaseBlock::RATIOS],
        ShowcaseBlock::COMPARE => ['ratio' => ShowcaseBlock::RATIOS],
        ShowcaseBlock::REASSURE => ['icon' => ShowcaseBlock::ICONS],
        ShowcaseBlock::REVIEW => ['rating' => ShowcaseBlock::RATINGS],
    ];

    /**
     * Fields that are an **address** (2026-09-30).
     *
     * Like a code in both the ways that matter here: English-only, because
     * an address has no French, and validated rather than merely bounded.
     * Unlike one in the way that matters most — it is not a closed set, and
     * it is the single field on this page whose value a stranger's browser
     * will go and *fetch*. Everything else is plain text the platform
     * renders itself.
     *
     * @var array<string, list<string>>
     */
    private const ADDRESSES = [
        ShowcaseBlock::DEMO => ['embed_url'],
        ShowcaseBlock::COMPARE => ['embed_url'],
    ];

    /**
     * The other codes a band carries (2026-11-23), each English only and
     * each checked for what it is rather than against a list of words:
     *
     * ```text
     * LINKS      an https address, or a #place on the page itself
     * DATES      a day, YYYY-MM-DD
     * VIDEOS     an https address on one of ShowcaseBlock::VIDEO_HOSTS
     * POSITIONS  a share of the picture, 0 to 100
     * VERDICTS   one of ShowcaseBlock::VERDICTS
     * ```
     */
    private const LINKS = [
        ShowcaseBlock::ANNOUNCE => ['link_url'],
    ];

    private const DATES = [
        ShowcaseBlock::ANNOUNCE => ['until'],
        ShowcaseBlock::REVIEW => ['date'],
    ];

    private const VIDEOS = [
        ShowcaseBlock::VIDEO => ['video_url'],
    ];

    private const POSITIONS = [
        ShowcaseBlock::HOTSPOT => ['x', 'y'],
    ];

    private const VERDICTS = [
        ShowcaseBlock::VERSUS => ['ours', 'first', 'second', 'third'],
    ];

    /**
     * The longest address stored. Generous for query parameters an embedded
     * application wants, short of somewhere to hide a payload.
     */
    private const LONGEST_ADDRESS = 1_000;

    /**
     * What the page substitutes into an address before it loads it.
     *
     * The operator writes `…&x={width}&y={height}…`; the page puts in the
     * pixels it actually rendered at. **The tokens are the platform's and
     * the parameter names are the product's** — Plan calls them `x` and `y`,
     * and nothing here knows that. A shared component naming one product's
     * query parameters is UR5, which `gate:products` forbids in PHP and
     * which has no gate on this side.
     *
     * @var list<string>
     */
    public const TOKENS = ['{width}', '{height}'];

    /** Long enough for a paragraph, short enough that nobody pastes a book. */
    private const LONGEST = 2_000;

    /**
     * @return list<ShowcaseBlock>
     */
    public static function of(JsonBody $body, string $field): array
    {
        $blocks = [];
        $seen = [];
        $counted = [];

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

            $most = ShowcaseBlock::AT_MOST[$block->block] ?? null;

            if ($most !== null) {
                $counted[$block->block] = ($counted[$block->block] ?? 0) + 1;

                if ($counted[$block->block] > $most) {
                    throw self::invalid($index, 'block', sprintf('at most %d %s on a page', $most, $block->block));
                }
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * The order the page reads its sections in, or null where the request
     * says nothing about it (2026-09-28).
     *
     * **Absent is not the default order**, it is "leave it alone" — and that
     * distinction is load-bearing. The translation desk writes one sentence
     * through this same operation by re-reading the story and sending the
     * blocks back; it carries no order, so a request without one that reset
     * the order would make translating a headline reorder the page.
     *
     * A **permutation**, checked here: every section exactly once. A subset
     * would be a section nobody could put back from the screen that sent it,
     * and hiding a band is removing its rows — which the editor above
     * already does. Two ways of saying the same thing eventually disagree.
     *
     * @return list<string>|null
     */
    public static function sections(JsonBody $body, string $field): ?array
    {
        if (!$body->has($field)) {
            return null;
        }

        // Through the shared reader, so `["", 1]` and `"nope"` are refused
        // by the same rule every other list on this platform is read with.
        $sections = $body->optionalStringList($field);

        if (!ShowcaseSections::isAPermutation($sections)) {
            throw self::invalidOrder(
                'each of ' . implode(', ', ShowcaseSections::DEFAULT_ORDER) . ' exactly once',
            );
        }

        return $sections;
    }

    private static function invalidOrder(string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => 'sections', 'requirement' => $requirement],
        );
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

        $assetId = $raw->asset_id ?? null;

        if ($assetId !== null && (!is_string($assetId) || !Uuid::isValid($assetId))) {
            throw self::invalid($index, 'asset_id', 'the id of a picture uploaded to this product, or null');
        }

        $content = self::content($raw->content ?? null, $kind, $index, true);

        // One or the other, decided with the operator (2026-09-30): a row
        // shows the product working or a picture of it, and a row carrying
        // both would leave the screen choosing — which is a decision about
        // somebody's shop window made in a component.
        if (in_array($kind, [ShowcaseBlock::DEMO, ShowcaseBlock::COMPARE], true)) {
            $embeds = isset($content['embed_url']);

            if ($embeds === ($assetId !== null)) {
                throw self::invalid(
                    $index,
                    'content.embed_url',
                    $embeds ? 'an address or a picture, not both' : 'an address, or a picture by asset_id',
                );
            }
        }

        // A film is its address: without one there is nothing to play.
        if ($kind === ShowcaseBlock::VIDEO && !isset($content['video_url'])) {
            throw self::invalid($index, 'content.video_url', 'a YouTube or Vimeo address');
        }

        // A point is somewhere: both shares, or the point is drawn nowhere.
        if ($kind === ShowcaseBlock::HOTSPOT && (!isset($content['x']) || !isset($content['y']))) {
            throw self::invalid($index, 'content.' . (isset($content['x']) ? 'y' : 'x'), 'a share of the picture, from 0 to 100');
        }

        // A review is its mark: stars drawn for a sentence nobody rated would
        // be a rating the page made up.
        if ($kind === ShowcaseBlock::REVIEW && !isset($content['rating'])) {
            throw self::invalid($index, 'content.rating', 'a whole number of stars, from 1 to 5');
        }

        return new ShowcaseBlock(
            // Ignored on the way in: the story is replaced wholly, so a
            // block's id is the database's to mint and never the client's
            // to choose.
            '',
            $kind,
            $position,
            $content,
            self::translations($raw->translations ?? null, $kind, $index),
            $assetId,
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
        $codes = $english ? array_keys(self::CODES[$kind] ?? []) : [];
        $addresses = $english ? self::ADDRESSES[$kind] ?? [] : [];
        $others = $english
            ? [
                ...self::LINKS[$kind] ?? [],
                ...self::DATES[$kind] ?? [],
                ...self::VIDEOS[$kind] ?? [],
                ...self::POSITIONS[$kind] ?? [],
                ...self::VERDICTS[$kind] ?? [],
            ]
            : [];
        $known = [...$shape['required'], ...$shape['optional'], ...$codes, ...$addresses, ...$others];
        $content = [];

        foreach ($known as $field) {
            $value = $raw->{$field} ?? null;

            if ($value === null) {
                continue;
            }

            if (!is_string($value) || mb_strlen($value) > self::LONGEST) {
                throw self::invalid($index, 'content.' . $field, sprintf('text of at most %d characters', self::LONGEST));
            }

            if (trim($value) === '') {
                continue;
            }

            $allowed = self::CODES[$kind][$field] ?? null;

            if ($allowed !== null && !in_array($value, $allowed, true)) {
                throw self::invalid($index, 'content.' . $field, 'one of ' . implode(', ', $allowed));
            }

            if (in_array($field, self::ADDRESSES[$kind] ?? [], true)) {
                self::assertAnAddress(trim($value), $field, $index);
            }

            if ($english) {
                self::assertTheCode($kind, $field, trim($value), $index);
            }

            $content[$field] = trim($value);
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
     * An address this platform will put in a frame.
     *
     * **`https` and nothing else.** Not because http is merely untidy: the
     * page is served over https, so an http frame is blocked as mixed
     * content — silently, which is the failure this whole band has to be
     * careful about — and every other scheme a browser knows (`javascript:`,
     * `data:`, `blob:`) is a way to run somebody else's code inside a page
     * this platform serves.
     *
     * **No credentials in it.** `https://user:pass@host/` is a valid URL and
     * a secret in a field five languages get translated from.
     *
     * The tokens are replaced by a digit before parsing: braces are not
     * valid in a URL, so a parser would refuse the very thing an operator is
     * meant to write. What is stored is what they wrote.
     */
    /**
     * The checks a code gets for what it is (2026-11-23). Refused here,
     * with the field named, rather than stored and drawn wrongly.
     */
    private static function assertTheCode(string $kind, string $field, string $value, int $index): void
    {
        if (in_array($field, self::LINKS[$kind] ?? [], true)) {
            if (preg_match('/^#[a-z][a-z0-9-]{0,40}$/', $value) !== 1) {
                self::assertAnAddress($value, $field, $index);
            }

            return;
        }

        if (in_array($field, self::DATES[$kind] ?? [], true)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($day === false || $day->format('Y-m-d') !== $value) {
                throw self::invalid($index, 'content.' . $field, 'a day, written YYYY-MM-DD');
            }

            return;
        }

        if (in_array($field, self::VIDEOS[$kind] ?? [], true)) {
            self::assertAnAddress($value, $field, $index);
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));

            if (!in_array($host, ShowcaseBlock::VIDEO_HOSTS, true)) {
                throw self::invalid($index, 'content.' . $field, 'a YouTube or Vimeo address');
            }

            return;
        }

        if (in_array($field, self::POSITIONS[$kind] ?? [], true)) {
            if (preg_match('/^(100|[1-9]?[0-9])(\.[0-9])?$/', $value) !== 1 || (float) $value > 100) {
                throw self::invalid($index, 'content.' . $field, 'a share of the picture, from 0 to 100');
            }

            return;
        }

        if (in_array($field, self::VERDICTS[$kind] ?? [], true) && !in_array($value, ShowcaseBlock::VERDICTS, true)) {
            throw self::invalid($index, 'content.' . $field, 'one of ' . implode(', ', ShowcaseBlock::VERDICTS));
        }
    }

    private static function assertAnAddress(string $value, string $field, int $index): void
    {
        if (mb_strlen($value) > self::LONGEST_ADDRESS) {
            throw self::invalid($index, 'content.' . $field, sprintf('an https address of at most %d characters', self::LONGEST_ADDRESS));
        }

        $parseable = str_replace(self::TOKENS, '1', $value);
        $parts = parse_url($parseable);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'], $parts['pass'])
            || isset($parts['user'])
            || filter_var($parseable, FILTER_VALIDATE_URL) === false
        ) {
            throw self::invalid(
                $index,
                'content.' . $field,
                'an https address, with no credentials in it; write ' . implode(' and ', self::TOKENS) . ' where the size goes',
            );
        }
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
