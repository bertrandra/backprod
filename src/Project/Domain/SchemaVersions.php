<?php

declare(strict_types=1);

namespace App\Project\Domain;

use App\Shared\Exceptions\BadRequestException;

/**
 * Which project document schema versions a product accepts (non-negotiable #10).
 *
 * The list is per-product configuration and this class never learns a product's
 * name — the same rule {@see \App\Project\Service\SchemaVersionPolicy} states.
 * What lives here is the *shape* of that configuration, in one place, because
 * it is read on every project write and written from the console, and those two
 * have to agree about what a valid list is.
 *
 * **The writer refuses exactly what the reader would drop.** Reading is
 * deliberately tolerant: a row written years ago, or by hand, must not take a
 * product down because one element is a string. Writing is not, and the
 * asymmetry is the point — a console that accepted `["2"]`, stored it, and
 * answered 200 would show a product accepting schema 2 while every project of
 * schema 2 was refused. That is the failure the configuration desk's own header
 * warns about: on screen, configuration nothing reads is indistinguishable from
 * configuration that did not save.
 *
 * **An empty list is refused on write**, although it is a perfectly ordinary
 * thing to read. A product that has declared nothing supports nothing, and that
 * is the intended state of a product nobody has answered for yet — not an
 * answer somebody gives. Offering a way to save it would let an operator stop a
 * product accepting any work at all through a form that looks like it worked.
 */
final class SchemaVersions
{
    /**
     * The longest list that can be stored.
     *
     * About the row rather than about any product's roadmap: a version list is
     * short by nature, and a bound keeps a pasted array from becoming a JSONB
     * document every project write has to decode.
     */
    public const LIMIT = 64;

    /** @param list<int> $versions */
    private function __construct(public readonly array $versions)
    {
    }

    /**
     * What a stored row means, tolerantly.
     *
     * Anything unrecognisable is dropped rather than raised: this runs on the
     * path of every project write, and a malformed row must refuse that one
     * document, not the product.
     *
     * @return list<int>
     */
    public static function read(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $supported = $configured['supported'] ?? null;

        if (!is_array($supported)) {
            return [];
        }

        return self::clean($supported);
    }

    /**
     * What a writer submitted, strictly.
     *
     * Whole numbers are the transport's business — {@see
     * \App\Shared\Http\JsonBody::requiredIntList()} refuses `"2"` before this
     * is reached, for the reason given there. What is left is what the reader
     * would silently drop, and this refuses all of it: a version below 1 is
     * dropped on read, so storing one would show a product accepting a version
     * it refuses.
     *
     * @param list<int> $submitted
     *
     * @throws BadRequestException when the list is not one the reader would
     *                             read back unchanged
     */
    public static function parse(array $submitted): self
    {
        if ($submitted === []) {
            throw self::invalid('must name at least one version — a product that accepts none is one nobody has configured, not one somebody saved');
        }

        foreach ($submitted as $version) {
            if ($version < 1) {
                throw self::invalid('every version must be 1 or more');
            }
        }

        return new self(self::clean($submitted));
    }

    /**
     * Already-valid versions, for code that built them rather than parsed them.
     *
     * @param list<int> $versions
     */
    public static function of(array $versions): self
    {
        return new self(self::clean($versions));
    }

    /**
     * @return array{supported: list<int>}
     */
    public function toConfiguration(): array
    {
        return ['supported' => $this->versions];
    }

    /**
     * Deduplicated and ordered, so that two lists naming the same versions are
     * the same row and a screen never has to sort what it was given.
     *
     * @param array<array-key, mixed> $versions
     *
     * @return list<int>
     */
    private static function clean(array $versions): array
    {
        $clean = [];

        foreach ($versions as $version) {
            if (is_int($version) && $version > 0 && !in_array($version, $clean, true)) {
                $clean[] = $version;
            }
        }

        sort($clean);

        return $clean;
    }

    private static function invalid(string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => 'supported', 'requirement' => $requirement],
        );
    }
}
