<?php

declare(strict_types=1);

/**
 * Proves a hand-built subscription says whose it is.
 *
 * Tests build worlds with SQL, and they should: making each one through the
 * sales chain would couple every test to that chain's correctness and take
 * minutes. What they must not do is build a row whose *shape* the
 * application never produces, because a test passing on an impossible row is
 * a test that proves nothing about the running platform.
 *
 * `subscriptions` is where that bites. The real chain writes sixteen columns;
 * a fixture that writes five has no `subscriber_kind` and no `owner_user_id`
 * — a shape the tenant surface has not produced since ADR-055, where every
 * subscription is addressed to somebody. Such a row is skipped by
 * `holdersCovering()`, counted as nobody by `PlacesUsedSql` and attributed to
 * nobody by `DocumentPersonSql`, so a test built on one exercises the
 * null-holder path while production exercises the other.
 *
 * It has already cost this repository twice in one week: a test that built
 * memberships in memory while the places rule reads `tenant_member_roles` in
 * SQL was green on a rule production applies differently, and a project
 * fixture with no holder went unreachable the day holders started deciding
 * reachability.
 *
 * **`subscriber_kind` and not `owner_user_id`**, deliberately. A `TENANT`
 * subscription legitimately names nobody — the column still carries the rows
 * a deployment already has — so demanding an owner would refuse an honest
 * fixture. Demanding the *kind* asks the author to decide which of the two
 * they are building and to say so, which is the fact everything downstream
 * reads.
 *
 * Exit 0 means every fixture names its subscriber.
 */

/**
 * Only so the message reads as English rather than as an index.
 */
function ordinal(int $n): string
{
    return match ($n) {
        1 => 'first',
        2 => 'second',
        3 => 'third',
        default => $n . 'th',
    };
}

$root = dirname(__DIR__);
$tests = $root . '/tests';

if (!is_dir($tests)) {
    fwrite(STDERR, "FAIL: tests/ is missing.\n");

    exit(1);
}

/** @var list<string> $offences */
$offences = [];
$checked = 0;

/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tests)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());

    if ($contents === false) {
        continue;
    }

    $path = ltrim(str_replace($root, '', $file->getPathname()), '/\\');

    // The column list that follows, across however many lines it is written
    // on. Anchored to the first `)` so a VALUES clause cannot be mistaken for
    // it, and case-insensitive because SQL in this repository is upper-case
    // by habit rather than by rule.
    if (preg_match_all('/INSERT\s+INTO\s+subscriptions\s*\(([^)]*)\)/is', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
        continue;
    }

    foreach ($matches[1] as $index => [$columns, $at]) {
        ++$checked;

        if (str_contains(strtolower($columns), 'subscriber_kind')) {
            continue;
        }

        $offences[] = sprintf(
            '%s:%d  the %s INSERT INTO subscriptions names no subscriber_kind',
            $path,
            substr_count(substr($contents, 0, $at), "\n") + 1,
            ordinal($index + 1),
        );
    }

    // An insert whose columns are assembled at run time cannot be read here,
    // and saying nothing about it would make this gate look stricter than it
    // is. There are none today; if one appears, it is named rather than
    // silently skipped.
    $written = preg_match_all('/INSERT\s+INTO\s+subscriptions/i', $contents);
    $read = count($matches[1]);

    if (is_int($written) && $written > $read) {
        $offences[] = sprintf(
            '%s  %d INSERT INTO subscriptions whose columns this gate could not read',
            $path,
            $written - $read,
        );
    }
}

if ($offences !== []) {
    sort($offences);

    fwrite(STDERR, sprintf("FAIL: %d fixture(s) build a subscription that names nobody.\n\n", count($offences)));

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    fwrite(STDERR, "\nEvery subscription this platform makes says whether it is one person's\n");
    fwrite(STDERR, "seat or the organisation's (ADR-055). A fixture that does not is a row\n");
    fwrite(STDERR, "the application never produces, and a test built on one proves nothing\n");
    fwrite(STDERR, "about the running platform. Add subscriber_kind, and an owner_user_id\n");
    fwrite(STDERR, "with it when the kind is USER.\n");

    exit(1);
}

printf("OK: all %d hand-built subscriptions name their subscriber.\n", $checked);
