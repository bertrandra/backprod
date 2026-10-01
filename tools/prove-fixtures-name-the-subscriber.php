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
 * a fixture that writes five names nobody — a shape the tenant surface has not
 * produced since ADR-055, where every subscription is addressed to somebody.
 * Such a row is skipped by `holdersCovering()`, counted as nobody by
 * `PlacesUsedSql` and attributed to nobody by `DocumentPersonSql`, so a test
 * built on one exercises the null-holder path while production exercises the
 * other.
 *
 * It has already cost this repository twice in one week: a test that built
 * memberships in memory while the places rule reads `tenant_member_roles` in
 * SQL was green on a rule production applies differently, and a project
 * fixture with no holder went unreachable the day holders started deciding
 * reachability.
 *
 * **It asked for `subscriber_kind` until 2026-10-01**, and that was the right
 * question while there were two kinds: a `TENANT` row legitimately named
 * nobody, so demanding a person would have refused an honest fixture, and
 * demanding the kind made the author say which of the two they were building.
 *
 * There is one kind now and the column is gone, so the question is simply
 * **who**: `subscriber_user_id`, which the schema makes NOT NULL. The gate
 * survives its own subject because what it was ever about is a fixture
 * building a row the application cannot — and PostgreSQL now refuses the row
 * outright, which makes this a second lock on the same door rather than the
 * only one. It is kept for the reason it was written: a column constraint says
 * *refused*, and this says *which fixture, on which line*.
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

        if (str_contains(strtolower($columns), 'subscriber_user_id')) {
            continue;
        }

        $offences[] = sprintf(
            '%s:%d  the %s INSERT INTO subscriptions names no subscriber_user_id',
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

    fwrite(STDERR, "\nEvery subscription this platform makes is one person's seat (ADR-055,\n");
    fwrite(STDERR, "and since 2026-10-01 the schema says so too). A fixture that names\n");
    fwrite(STDERR, "nobody is a row the application never produces, and a test built on one\n");
    fwrite(STDERR, "proves nothing about the running platform. Add subscriber_user_id, and\n");
    fwrite(STDERR, "owner_user_id with it unless the case is about a row that has none.\n");

    exit(1);
}

printf("OK: all %d hand-built subscriptions name their subscriber.\n", $checked);
