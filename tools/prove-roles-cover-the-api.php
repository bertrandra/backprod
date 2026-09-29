<?php

declare(strict_types=1);

/**
 * Proves `docs/three-roles-end-to-end.md` accounts for every operation.
 *
 * That document claims 100% coverage: every operation the contract declares is
 * attributed to somebody who can reach it, or named as one nobody reaches by
 * hand. A claim like that is true on the day it is written and false a
 * fortnight later, and a coverage document nobody checks is worse than none —
 * because it is believed.
 *
 * So this checks it in both directions, like `prove-ui-covers-the-api.php`:
 *
 *   - every operation in `openapi.json` is named in the document;
 *   - every operation the document names is still in `openapi.json`;
 *   - the tally under "L'arithmétique" adds up, and adds up to the contract.
 *
 * The second direction is the one that catches the slow rot. An endpoint
 * removed from the contract and left in the prose is a role described doing
 * something the platform no longer offers.
 *
 * The third was added after the tally was found adrift: it read 110 lectures
 * and 118 écritures against a contract declaring 109 GET and 119 others.
 * Names were checked and the arithmetic under them was not, so it rotted in
 * the one place a reader takes on trust — nobody recounts a total by hand.
 * It is the gate's own warning turned on the gate.
 *
 * Operations are recognised by their backticked name. That is how the document
 * writes them, and it is a habit worth enforcing: an operation mentioned in
 * running prose without backticks is one a reader cannot search for.
 *
 * Exit 0 means the three roles and the contract describe the same platform.
 */

$root = dirname(__DIR__);

// --- What the contract declares ----------------------------------------------

$contract = $root . '/openapi.json';

if (!is_file($contract)) {
    fwrite(STDERR, "FAIL: openapi.json is missing. The contract comes first.\n");

    exit(1);
}

try {
    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($contract), true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'FAIL: openapi.json is not valid JSON — ' . $e->getMessage() . "\n");

    exit(1);
}

/** @var array<string, true> $declared */
$declared = [];

/**
 * The read/write split, taken from the verb and nothing else. A GET is a
 * lecture; everything else writes, or is declared as though it might.
 */
$reads = 0;
$writes = 0;

/** @var array<string, mixed> $paths */
$paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

foreach ($paths as $methods) {
    if (!is_array($methods)) {
        continue;
    }

    foreach ($methods as $method => $operation) {
        if (!is_array($operation) || !is_string($operation['operationId'] ?? null)) {
            continue;
        }

        $declared[$operation['operationId']] = true;

        if (strtolower((string) $method) === 'get') {
            ++$reads;
        } else {
            ++$writes;
        }
    }
}

if ($declared === []) {
    fwrite(STDERR, "FAIL: openapi.json declares no operationId, which cannot be right.\n");

    exit(1);
}

// --- What the document names --------------------------------------------------

$page = $root . '/docs/three-roles-end-to-end.md';

if (!is_file($page)) {
    fwrite(STDERR, "FAIL: docs/three-roles-end-to-end.md is missing.\n");

    exit(1);
}

$prose = (string) file_get_contents($page);

/**
 * Backticked camelCase words that are not operations and never will be: a PHP
 * method the document quotes, a column, a status. Listed rather than guessed
 * at, because the alternative is a pattern loose enough to miss a real
 * operation that has been removed from the contract.
 *
 * @var list<string>
 */
$notOperations = [
    'requireSubscription',
    'requirePermission',
    'lastReadSeq',
    'sinceSeq',
    'clientSecret',
    'maxProjects',
    'schemaVersion',
    'tenantProducts',
    'platformStaff',
    'tenantMembers',
    'staffAccessLog',
    'billingPay',
    'HttpOnly',
];

if (preg_match_all('/`([a-z][A-Za-z]{3,})`/', $prose, $matches) === false) {
    fwrite(STDERR, "FAIL: the document could not be scanned.\n");

    exit(1);
}

/**
 * Two sets, because the two directions need different strictness.
 *
 * Forward — "is every operation named?" — takes every backticked word, so an
 * all-lowercase name like `health` counts.
 *
 * Backward — "does the document name something that is gone?" — takes only
 * the camel-humped ones, because a backticked `slug` or `cancelled` is prose
 * and flagging it would teach everybody to ignore this gate.
 *
 * @var array<string, true> $named
 */
$named = [];

/** @var array<string, true> $couldBeAnOperation */
$couldBeAnOperation = [];

foreach ($matches[1] as $word) {
    if (in_array($word, $notOperations, true)) {
        continue;
    }

    $named[$word] = true;

    if (preg_match('/[A-Z]/', $word) === 1) {
        $couldBeAnOperation[$word] = true;
    }
}

// --- Both directions ----------------------------------------------------------

$missing = array_keys(array_diff_key($declared, $named));
$stale = array_keys(array_diff_key($couldBeAnOperation, $declared));

sort($missing);
sort($stale);

if ($missing !== []) {
    fwrite(STDERR, sprintf(
        "FAIL: %d operation(s) the contract declares are in no role's chapter.\n\n",
        count($missing),
    ));

    foreach ($missing as $operation) {
        fwrite(STDERR, '  ' . $operation . "\n");
    }

    fwrite(STDERR, "\nEvery operation belongs to somebody, or is named as one nobody reaches\n");
    fwrite(STDERR, "by hand. Add it to docs/three-roles-end-to-end.md, in backticks.\n");
}

if ($stale !== []) {
    fwrite(STDERR, sprintf(
        "\nFAIL: %d name(s) in the document are no operation the contract declares.\n\n",
        count($stale),
    ));

    foreach ($stale as $operation) {
        fwrite(STDERR, '  ' . $operation . "\n");
    }

    fwrite(STDERR, "\nEither the contract lost an operation the document still describes, or\n");
    fwrite(STDERR, "a backticked word is prose — add it to \$notOperations if so.\n");
}

if ($missing !== [] || $stale !== []) {
    exit(1);
}

// --- The arithmetic -----------------------------------------------------------

/**
 * The tally is read as structure, not as four named numbers: every indented
 * "label   number" line inside the fenced block is a component, except the
 * two the rules introduce — a subtotal and the total. So a line added to the
 * block is counted without this gate being edited, which is the only way a
 * hand-kept total stays true.
 */
if (preg_match("/# L'arithmétique\n\n```text\n(.*?)\n```/s", $prose, $block) !== 1) {
    fwrite(STDERR, "FAIL: the arithmetic block is missing or no longer fenced as ```text.\n");

    exit(1);
}

/** @var list<array{string, int}> $lines a label and its number, in order */
$lines = [];

foreach (explode("\n", $block[1]) as $line) {
    if (preg_match('/^\s+(.+?)\s{2,}(\d+)\b/u', $line, $found) === 1) {
        $lines[] = [trim($found[1]), (int) $found[2]];
    }
}

/**
 * Structure: components, a rule, the tenant subtotal, more components, a
 * rule, the total. The two rules are the only lines drawn, so the subtotal
 * is the line after the first and the total is the last.
 */
$rules = preg_match_all('/^\s*─+\s*$/mu', $block[1]);

if ($rules !== 2 || count($lines) < 4) {
    fwrite(STDERR, "FAIL: the arithmetic block no longer has two rules and a tally under each.\n");

    exit(1);
}

$total = array_pop($lines);
$subtotalAt = null;

foreach ($lines as $i => [$label]) {
    if (str_contains($label, 'surface locataire')) {
        $subtotalAt = $i;
    }
}

if ($subtotalAt === null) {
    fwrite(STDERR, "FAIL: the arithmetic block no longer names a 'surface locataire' subtotal.\n");

    exit(1);
}

$subtotal = $lines[$subtotalAt][1];
$tenant = 0;
$rest = 0;

foreach ($lines as $i => [, $count]) {
    if ($i < $subtotalAt) {
        $tenant += $count;
    } elseif ($i > $subtotalAt) {
        $rest += $count;
    }
}

/** @var list<string> $wrong */
$wrong = [];

if ($tenant !== $subtotal) {
    $wrong[] = sprintf('the tenant lines add up to %d, and the subtotal says %d', $tenant, $subtotal);
}

if ($subtotal + $rest !== $total[1]) {
    $wrong[] = sprintf('the lines add up to %d, and the total says %d', $subtotal + $rest, $total[1]);
}

if ($total[1] !== count($declared)) {
    $wrong[] = sprintf('the total says %d, and the contract declares %d', $total[1], count($declared));
}

if (preg_match('/Dont \*\*(\d+) lectures\*\* et \*\*(\d+) écritures\*\*/u', $prose, $split) !== 1) {
    $wrong[] = 'the read/write split is no longer stated in the form the gate can read';
} else {
    if ((int) $split[1] !== $reads) {
        $wrong[] = sprintf('the document counts %d lectures, and the contract declares %d GET', (int) $split[1], $reads);
    }

    if ((int) $split[2] !== $writes) {
        $wrong[] = sprintf('the document counts %d écritures, and the contract declares %d others', (int) $split[2], $writes);
    }
}

if ($wrong !== []) {
    fwrite(STDERR, "FAIL: the arithmetic does not hold.\n\n");

    foreach ($wrong as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }

    fwrite(STDERR, "\nRecount docs/three-roles-end-to-end.md under \"L'arithmétique\". A total\n");
    fwrite(STDERR, "nobody can check is the one a reader believes.\n");

    exit(1);
}

printf(
    "OK: all %d operations (%d lectures, %d écritures) are attributed to a role, the document names\n"
    . "    no operation the contract has dropped, and its arithmetic adds up.\n",
    count($declared),
    $reads,
    $writes,
);
