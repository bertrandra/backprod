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
 *   - every operation the document names is still in `openapi.json`.
 *
 * The second direction is the one that catches the slow rot. An endpoint
 * removed from the contract and left in the prose is a role described doing
 * something the platform no longer offers.
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

/** @var array<string, mixed> $paths */
$paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

foreach ($paths as $methods) {
    if (!is_array($methods)) {
        continue;
    }

    foreach ($methods as $operation) {
        if (is_array($operation) && is_string($operation['operationId'] ?? null)) {
            $declared[$operation['operationId']] = true;
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

printf(
    "OK: all %d operations are attributed to a role, and the document names no operation the contract has dropped.\n",
    count($declared),
);
