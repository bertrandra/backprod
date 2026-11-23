<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * One band of a product's story (2026-09-24,
 * `docs/home-showcase-spec.md` §7).
 *
 * The English lives on the block and is the key and the fallback; the four
 * other languages are `$translations`, keyed by locale — the mechanism
 * `docs/translatable-fields-spec.md` settled and the third thing to use it
 * after feature names and offer names.
 *
 * `$content` is the band's own fields and is deliberately untyped here: a
 * headline has a headline and a subline, a question has a question and an
 * answer, and a domain that knew all five shapes would need changing every
 * time a band was added — which is the one thing §6 promises does not
 * happen. What guards it is the validator at the HTTP boundary, which is
 * where a shape arrives from outside.
 */
final class ShowcaseBlock
{
    public const HEADLINE = 'HEADLINE';
    public const PROBLEM = 'PROBLEM';
    public const STEPS = 'STEPS';
    public const QUOTE = 'QUOTE';
    public const USE_CASE = 'USE_CASE';
    public const PROOF = 'PROOF';
    public const DEMO = 'DEMO';
    public const QUESTION = 'QUESTION';

    /**
     * Four bands that sell (2026-11-20): who already trusts it, what it
     * amounts to in numbers, what it includes, and a last place to act.
     *
     * The page could say what a product does and prove it with screenshots,
     * and had nowhere to put the three things a hesitating reader looks for
     * next — a name they recognise, a figure, a list of what they get — nor
     * anything after the questions but the end of the page.
     */
    public const TRUST = 'TRUST';
    public const STATS = 'STATS';
    public const FEATURE = 'FEATURE';
    public const CTA = 'CTA';

    /**
     * Two bands that show rather than say (2026-11-21).
     *
     * `TOUR` is what the product includes, each with its own screenshot, one
     * shown at a time: six things in the room of one. `COMPARE` is one plot
     * twice — before and after — as two pictures a reader slides between, or
     * as two embedded pages they switch between and move around in.
     */
    public const TOUR = 'TOUR';
    public const COMPARE = 'COMPARE';

    /** A line above the page: news, with a link and an end date (2026-11-23). */
    public const ANNOUNCE = 'ANNOUNCE';

    /** A film of the product, played on a click (2026-11-23). */
    public const VIDEO = 'VIDEO';

    /** The product against the other ways of doing it, criterion by criterion (2026-11-23). */
    public const VERSUS = 'VERSUS';

    /** One picture, and points on it that explain what is where (2026-11-23). */
    public const HOTSPOT = 'HOTSPOT';

    /** A form a visitor writes to the operator with (2026-11-23). */
    public const CONTACT = 'CONTACT';

    /**
     * Every kind an operator writes rows for.
     *
     * `PRICING` is absent and must stay absent: it is a position in the
     * order and reads the catalogue. A block kind for it would be a row
     * somebody could type a price into, which is the one thing the
     * specification forbids outright (§9).
     *
     * @var list<string>
     */
    public const KINDS = [
        self::HEADLINE,
        self::ANNOUNCE,
        self::TRUST,
        self::PROBLEM,
        self::STATS,
        self::VIDEO,
        self::STEPS,
        self::FEATURE,
        self::HOTSPOT,
        self::TOUR,
        self::USE_CASE,
        self::VERSUS,
        self::QUOTE,
        self::PROOF,
        self::COMPARE,
        self::DEMO,
        self::QUESTION,
        self::CONTACT,
        self::CTA,
    ];

    /**
     * The kinds a page has a bounded number of rows of, beyond the ones it
     * has exactly one of.
     *
     * `COMPARE` is two sides and no third: a comparison of three things is
     * a gallery, which the proof band already is. Said by the validator in
     * words; nothing in the database counts it, because two saves at once
     * cannot add rows to a story that is replaced whole.
     *
     * @var array<string, int>
     */
    public const AT_MOST = [self::COMPARE => 2, self::HOTSPOT => 12];

    /**
     * The icons a `PROBLEM` row may carry.
     *
     * A closed set, and a **code rather than a sentence**: the frontend
     * draws it, so it has no French and is refused in a translation the way
     * a price is refused in a band. Free text here would be either a class
     * name from a library this platform does not ship or an operator's
     * afternoon spent typing into a hole.
     *
     * @var list<string>
     */
    public const ICONS = [
        'clock', 'cross', 'house', 'coin', 'ruler', 'paper', 'warning', 'repeat',
        // Four that say something good (2026-11-20). The first eight were
        // drawn for the problem band, and a feature list illustrated only
        // with a warning triangle and a cross reads as a list of faults.
        'check', 'bolt', 'shield', 'layers',
    ];

    /**
     * The shapes a `DEMO` row may reserve, as width over height.
     *
     * A closed set for the reason `ICONS` is one: it decides the box the page
     * holds before anything loads, and a free field would eventually hold
     * `4/3`, `1.333` and "four to three". The frontend draws it, so it has no
     * French and is refused in a translation.
     *
     * **It was offered by the console and refused by this endpoint** from the
     * day the band shipped until 2026-09-30: the field was in `BAND_FIELDS`,
     * read by the page, and declared nowhere here — so saving the home page
     * answered `no such field on a DEMO`. The seeder writes rows straight to
     * the table and never met the validator, which is why the demonstration
     * worked and the console did not.
     *
     * @var list<string>
     */
    public const RATIOS = ['16:9', '4:3', '3:2', '1:1'];

    /**
     * The kinds that hold exactly one row.
     *
     * Said here as well as by `product_showcase_one_headline`, because a
     * partial unique index refuses with a constraint's name and somebody
     * adding a second hero is owed the reason.
     *
     * @var list<string>
     */
    public const SINGLETONS = [self::HEADLINE, self::CTA, self::ANNOUNCE, self::VIDEO, self::CONTACT];

    /**
     * What one way of doing it does about one criterion, in a comparison
     * table: a code the page draws as a mark, never a sentence.
     */
    public const VERDICTS = ['yes', 'partly', 'no'];

    /**
     * Where a film may come from (2026-11-23).
     *
     * Two players and nothing else, because the page frames what is written
     * here and the Content-Security-Policy has to name every origin it may
     * frame: a list the policy can match, rather than any address somebody
     * pastes. The page plays YouTube from its no-cookie origin.
     */
    public const VIDEO_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    /**
     * Whether this row is on the page on a given day (`Y-m-d`).
     *
     * An announcement says until when it is news; past that day it is not
     * read out to anybody. Decided on read, by the server, so a page left
     * published does not go on announcing last month — and every other row
     * is always shown.
     */
    public function isShownOn(string $day): bool
    {
        if ($this->block !== self::ANNOUNCE) {
            return true;
        }

        $until = $this->content['until'] ?? null;

        return !is_string($until) || $until === '' || $until >= $day;
    }

    /**
     * @param array<string, mixed>                $content      the English
     * @param array<string, array<string, mixed>> $translations by locale
     */
    public function __construct(
        public readonly string $id,
        public readonly string $block,
        public readonly int $position,
        public readonly array $content,
        public readonly array $translations = [],
        public readonly ?string $assetId = null,
    ) {
    }

    /**
     * The band's fields in one language, falling back field by field.
     *
     * **Field by field, not row by row.** A half-translated block is the
     * ordinary state of a catalogue somebody is still working through
     * (home-showcase-spec §11.2), and answering the whole English row
     * because one field was missing would throw away the sentences that
     * *were* translated. So a French headline with no French subline reads
     * French above and English below, which is the honest rendering and the
     * one the console's language dots are for.
     *
     * @return array<string, mixed>
     */
    public function contentIn(string $locale): array
    {
        $translated = $this->translations[$locale] ?? [];
        $resolved = $this->content;

        foreach ($translated as $field => $value) {
            if (is_string($value) && trim($value) !== '') {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }
}
