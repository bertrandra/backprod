<?php

declare(strict_types=1);

/**
 * Proves every field the console offers on a showcase band is one the
 * endpoint accepts, and every field it accepts is one the console offers.
 *
 * The two lists are written in two languages and read by nobody together.
 * `BAND_FIELDS` in `editors.tsx` decides what an operator sees; `FIELDS`,
 * `CODES` and `ADDRESSES` in `ShowcaseBlocks` decide what the server keeps.
 * Nothing held them to each other, and on 2026-09-30 they came apart the day
 * the `DEMO` band shipped: the console offered `ratio`, the page read it, and
 * the endpoint had never heard of it — so saving the home page answered
 * `no such field on a DEMO`, from the first deployment.
 *
 * It survived CI because the demonstration seeds rows **straight to the
 * table**, never meeting the validator, and no test wrote a `DEMO` row
 * through the endpoint. The world looked right and the console could not save.
 *
 * Both directions, because each catches a different mistake:
 *
 *   - offered and not accepted is a form that refuses on submit;
 *   - accepted and not offered is a field an operator cannot reach, and
 *     worse, one `toInput()` drops on the next save from that screen — which
 *     is how `alt` was silently destroyed until 2026-09-28.
 *
 * This is `gate:permissions` applied to a second vocabulary: the same shape
 * of promise, checked the same way.
 *
 * Exit 0 means the console and the endpoint agree about every band.
 */

$root = dirname(__DIR__);
$editors = $root . '/frontend/src/features/showcase/blocks/editors.tsx';
$validator = $root . '/src/Product/Controller/ShowcaseBlocks.php';

foreach ([$editors, $validator] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, 'FAIL: ' . $file . " is missing.\n");

        exit(1);
    }
}

/**
 * What the console offers, by band.
 *
 * `PICTURE_DESCRIPTION` is a shared constant rather than a literal, so it is
 * resolved by name — the one indirection this file has, and hard-coding its
 * field would be this gate having its own third list.
 *
 * @return array<string, list<string>>
 */
function offered(string $source): array
{
    $table = preg_match('/BAND_FIELDS[^=]*=\s*\{(.*)\n\};/s', $source, $found) === 1 ? $found[1] : '';

    if ($table === '') {
        fwrite(STDERR, "FAIL: BAND_FIELDS could not be read from editors.tsx.\n");

        exit(1);
    }

    /** @var array<string, list<string>> $bands */
    $bands = [];
    $band = null;

    foreach (explode("\n", $table) as $line) {
        if (preg_match('/^\s{2}([A-Z_]+):\s*\[/', $line, $named) === 1) {
            $band = $named[1];
            $bands[$band] ??= [];
        }

        if ($band === null) {
            continue;
        }

        if (preg_match_all("/name:\s*'([a-z_]+)'/", $line, $fields) === 1 || ($fields[1] ?? []) !== []) {
            foreach ($fields[1] as $field) {
                $bands[$band][] = $field;
            }
        }

        if (str_contains($line, 'PICTURE_DESCRIPTION')) {
            $bands[$band][] = 'alt';
        }
    }

    return array_map(static fn (array $fields): array => array_values(array_unique($fields)), $bands);
}

/**
 * What the endpoint accepts, by band: the required, the optional, the codes
 * and the addresses, which are four lists for one question.
 *
 * @return array<string, list<string>>
 */
function accepted(string $source): array
{
    /** @var array<string, list<string>> $bands */
    $bands = [];

    // `FIELDS`: required and optional, on one line per band.
    if (preg_match_all("/ShowcaseBlock::([A-Z_]+) => \['required' => \[([^\]]*)\], 'optional' => \[([^\]]*)\]\]/", $source, $rows, PREG_SET_ORDER) !== false) {
        foreach ($rows as $row) {
            $bands[$row[1]] = [...names($row[2]), ...names($row[3])];
        }
    }

    // `CODES` and `ADDRESSES`, whose shapes differ and whose meaning here
    // does not: a field the endpoint will store.
    if (preg_match_all("/ShowcaseBlock::([A-Z_]+) => \['([a-z_]+)' => ShowcaseBlock::[A-Z_]+\]/", $source, $codes, PREG_SET_ORDER) !== false) {
        foreach ($codes as $code) {
            $bands[$code[1]][] = $code[2];
        }
    }

    if (preg_match_all("/ShowcaseBlock::([A-Z_]+) => \[('[a-z_]+'(?:,\s*'[a-z_]+')*)\],/", $source, $addresses, PREG_SET_ORDER) !== false) {
        foreach ($addresses as $address) {
            foreach (names($address[2]) as $field) {
                $bands[$address[1]][] = $field;
            }
        }
    }

    return array_map(static fn (array $fields): array => array_values(array_unique($fields)), $bands);
}

/**
 * @return list<string>
 */
function names(string $list): array
{
    return preg_match_all("/'([a-z_]+)'/", $list, $found) > 0 ? $found[1] : [];
}

$console = offered((string) file_get_contents($editors));
$server = accepted((string) file_get_contents($validator));

if ($console === [] || $server === []) {
    fwrite(STDERR, "FAIL: one of the two lists came back empty, which cannot be right.\n");

    exit(1);
}

/** @var list<string> $offences */
$offences = [];

foreach ($console as $band => $fields) {
    if (!isset($server[$band])) {
        $offences[] = sprintf('%s: the console offers it and the endpoint knows no such band', $band);

        continue;
    }

    foreach (array_diff($fields, $server[$band]) as $field) {
        $offences[] = sprintf('%s.%s is offered by the console and refused by the endpoint', $band, $field);
    }

    foreach (array_diff($server[$band], $fields) as $field) {
        $offences[] = sprintf('%s.%s is accepted by the endpoint and offered by nothing', $band, $field);
    }
}

foreach (array_diff(array_keys($server), array_keys($console)) as $band) {
    $offences[] = sprintf('%s: the endpoint accepts it and the console offers no form for it', $band);
}

if ($offences !== []) {
    sort($offences);

    fwrite(STDERR, sprintf("FAIL: the console and the endpoint disagree about %d field(s).\n\n", count($offences)));

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    fwrite(STDERR, "\nA field offered and not accepted is a form that refuses on submit. One\n");
    fwrite(STDERR, "accepted and not offered is a field nobody can reach, and that toInput()\n");
    fwrite(STDERR, "drops on the next save from that screen. Add it to BAND_FIELDS in\n");
    fwrite(STDERR, "editors.tsx, or to FIELDS/CODES/ADDRESSES in ShowcaseBlocks.\n");

    exit(1);
}

printf(
    "OK: the console and the endpoint agree about every field of all %d bands.\n",
    count($console),
);
