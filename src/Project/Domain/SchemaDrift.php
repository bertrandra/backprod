<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * Whether a product can accept the documents it is about to be sent.
 *
 * Which versions a product accepts is configuration and not a constant,
 * deliberately (ADR-018, non-negotiable #10): a second product declares its own
 * as a row, and no code learns either product's name. The price of that is a
 * fact the code and the database can hold differently — a database keeps the
 * list it was seeded with, so shipping a release that accepts one more version
 * changes nothing at all until somebody opens the console.
 *
 * It has cost this platform twice, the same way both times: Plan's 2.2.0 saving
 * in schema 2 against a list of `[1]`, and its roofs saving in schema 3 against
 * `[1, 2]`. Each time the symptom was `UNSUPPORTED_SCHEMA_VERSION` on every
 * save, and each time it read as a bug in Plan. Nothing compared the two lists
 * because there was nowhere that held both — which is what this is.
 *
 * **Reasons and not sentences.** `bin/preflight.php` is the caller today and
 * the console could be tomorrow; prose belongs to whoever is doing the
 * speaking. What belongs here is which of the three things is wrong, because
 * they are three different things to go and fix, and a single "the versions
 * are wrong" would send an operator to the console for a problem the console
 * cannot solve.
 */
final class SchemaDrift
{
    /**
     * The product accepts no project of any version.
     *
     * The intended state of a product nobody has answered for yet
     * ({@see SchemaVersions}), and a defect for one that is live: every save
     * is refused, and the product looks broken rather than unconfigured.
     */
    public const DECLARES_NOTHING = 'DECLARES_NOTHING';

    /**
     * The code expects the product to accept versions the database does not
     * name. `versions` is the difference, and the next release writes them.
     */
    public const BEHIND_THE_CODE = 'BEHIND_THE_CODE';

    /**
     * Documents are already stored in versions the product no longer accepts —
     * the same failure, arriving from the other direction. Their owners can
     * open them and cannot save them.
     */
    public const DOCUMENTS_REFUSED = 'DOCUMENTS_REFUSED';

    /**
     * @param list<int>  $stored    what the database says the product accepts
     * @param ?list<int> $declared  what the code says it accepts, or null when
     *                              the code has never heard of this product —
     *                              one created in the console, which has no
     *                              declaration to disagree with rather than an
     *                              empty one to be mistaken for an opinion
     * @param list<int>  $documents the versions it already has projects in
     *
     * @return list<array{reason: string, versions: list<int>}>
     */
    public static function of(array $stored, ?array $declared, array $documents): array
    {
        $found = [];

        if ($stored === []) {
            // And nothing else. A product accepting nothing is already told
            // the whole story, and adding "it is also behind the code" would
            // be two findings for one fix.
            return [['reason' => self::DECLARES_NOTHING, 'versions' => []]];
        }

        // Only what the code declares and the database lacks. The other
        // direction is an operator who added a version on purpose, and
        // reporting it would be asking them to undo their own decision — the
        // console is the authority here, which is the whole point of the list
        // being configuration.
        $missing = $declared === null ? [] : self::missing($declared, $stored);

        if ($missing !== []) {
            $found[] = ['reason' => self::BEHIND_THE_CODE, 'versions' => $missing];
        }

        $refused = self::missing($documents, $stored);

        if ($refused !== []) {
            $found[] = ['reason' => self::DOCUMENTS_REFUSED, 'versions' => $refused];
        }

        return $found;
    }

    /**
     * What is in the first list and not the second, deduplicated and ordered.
     *
     * `array_diff` keeps the original keys and would hand a caller a list with
     * holes in it, which `json_encode` then writes as an object.
     *
     * @param list<int> $wanted
     * @param list<int> $held
     *
     * @return list<int>
     */
    private static function missing(array $wanted, array $held): array
    {
        $missing = [];

        foreach ($wanted as $version) {
            if (!in_array($version, $held, true) && !in_array($version, $missing, true)) {
                $missing[] = $version;
            }
        }

        sort($missing);

        return $missing;
    }
}
