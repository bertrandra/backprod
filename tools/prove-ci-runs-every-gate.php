<?php

declare(strict_types=1);

/**
 * Proves the gate chain CI runs is the gate chain this repository defines.
 *
 * `composer run gates` is described in composer.json as running "the full quality
 * gate chain exactly as CI does". CI does not run that script. It runs each gate
 * as its own named step, deliberately — a job whose only step is "gates" reports
 * a failure as one red box and makes the reader open the log to learn which of
 * twelve checks objected — and the cost of that decision is two lists that must
 * agree and nothing making them.
 *
 * They did not agree. `gate:screens` landed in U9 as that milestone's headline —
 * the check `ui-spec.md` §7 said could not exist until a frontend existed — went
 * into the chain, and was never added to the workflow. It passed locally on every
 * run and ran in CI zero times. The gate that proves every screen calls the
 * operations it claims was, in the only place that can block a merge, absent.
 *
 * That is the same defect this repository keeps finding in its own work: a
 * promise nothing checks. The six wrong permission codes in U1, the doubled API
 * prefix in U6, a deployment that answers `/health` while authenticating nobody.
 * A gate nobody runs is worse than the others, because its output on a developer
 * machine reads exactly like enforcement.
 *
 * So, three directions:
 *
 *   1. Every `gate:*` script composer.json defines is in the `gates` chain. A
 *      gate written and never chained runs nowhere at all.
 *   2. Every step of that chain has a step in the workflow. This is the one that
 *      was broken.
 *   3. Every `composer run` in the workflow is either in the chain or named
 *      below as setup. Otherwise CI checks something `composer run gates` does
 *      not, and a green local run stops meaning anything.
 *
 * Parsing is deliberately textual rather than a YAML dependency: the question is
 * "does the string `composer run gate:screens` appear as a step's command", and
 * a regular expression answers exactly that. A step that is commented out does
 * not match, because a `#` line is not a `run:` line.
 *
 * Exit 0 means the chain and the workflow are the same list.
 */

$root = dirname(__DIR__);

/**
 * Commands CI runs that are setup rather than gates. `migrate` brings the
 * throwaway database to the schema the tests expect; it is not a check, and
 * putting it in the chain would make `composer run gates` write to whatever
 * database the developer happens to be pointing at.
 */
const SETUP = ['migrate'];

$composerPath = $root . '/composer.json';
$workflowPath = $root . '/.github/workflows/ci.yml';

foreach ([$composerPath, $workflowPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, sprintf("FAIL: %s is missing.\n", substr($required, strlen($root) + 1)));

        exit(1);
    }
}

/** @var array{scripts?: array<string, string|list<string>>} $composer */
$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
$scripts = $composer['scripts'] ?? [];

if (!is_array($scripts) || !isset($scripts['gates']) || !is_array($scripts['gates'])) {
    fwrite(STDERR, "FAIL: composer.json has no `gates` script, or it is not a chain of steps.\n");

    exit(1);
}

// "@cs" is Composer's syntax for calling another script in the same file. The
// chain is written that way, the workflow spells the script name out, and this
// is the only place the two notations meet.
$chain = array_map(
    static fn (string $step): string => ltrim($step, '@'),
    array_values(array_filter($scripts['gates'], 'is_string')),
);

$gates = array_values(array_filter(
    array_keys($scripts),
    static fn (string $name): bool => str_starts_with($name, 'gate:'),
));

$workflow = (string) file_get_contents($workflowPath);

/** @var list<array{string, string}> $matches */
preg_match_all('/^\s*run:\s*composer run ([\w:-]+)/m', $workflow, $matches);
$inWorkflow = array_values(array_unique($matches[1]));

$failures = [];

$unchained = array_values(array_diff($gates, $chain));

if ($unchained !== []) {
    $failures[] = sprintf(
        "These gates are defined in composer.json but are not in the `gates` chain,\nso nothing runs them:\n  %s",
        implode("\n  ", $unchained),
    );
}

$missingFromCi = array_values(array_diff($chain, $inWorkflow));

if ($missingFromCi !== []) {
    $failures[] = sprintf(
        "These steps of the `gates` chain have no step in .github/workflows/ci.yml,\n"
        . "so they pass locally and never run where a merge can be blocked:\n  %s\n\n"
        . "Add a step running `composer run <name>` for each, with a comment saying\n"
        . 'what it protects — every other step in that file has one.',
        implode("\n  ", $missingFromCi),
    );
}

$extraInCi = array_values(array_diff($inWorkflow, $chain, SETUP));

if ($extraInCi !== []) {
    $failures[] = sprintf(
        "CI runs these Composer scripts that the `gates` chain does not, so a green\n"
        . "`composer run gates` no longer means CI will be green:\n  %s\n\n"
        . 'Add each to the chain, or name it in SETUP here if it is setup rather than a check.',
        implode("\n  ", $extraInCi),
    );
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: the gate chain and CI disagree.\n\n");
    fwrite(STDERR, implode("\n\n", $failures) . "\n");

    exit(1);
}

printf(
    "OK: all %d steps of the `gates` chain (%d of them gates) run as named steps in CI, and CI runs nothing else.\n",
    count($chain),
    count($gates),
);

exit(0);
