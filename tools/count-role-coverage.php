<?php

declare(strict_types=1);

/**
 * How many operations the contract declares, and which chapter of
 * `docs/three-roles-end-to-end.md` names each.
 *
 * The total is the number that goes stale. It appears in that document, in
 * `README.md`, and on the web page published from the document —
 * `listTranslations` landed on 2026-09-26 and put all three a digit behind in
 * one commit. It is a number to look up rather than remember, which is what
 * this is for.
 *
 * **What this does not measure.** The document also states how many operations
 * each role *reaches* — 91, 119, 76 — and those come from the permissions a
 * role holds, resolved against the permission each controller requires. They
 * are not the same as the count below, and must not be read as such: a chapter
 * names what is distinctive about a role, so the TENANT_ADMIN chapter is short
 * precisely because an administrator also reaches most of what the USER
 * chapter lists. Deriving the reachability figures properly means walking
 * controllers to their `requirePermission` calls, which is
 * `prove-frontend-permissions-exist.php`'s territory and not this script's.
 *
 * `composer run gate:roles` already proves every operation is *attributed* in
 * some chapter. This prints the arithmetic that gate does not, and repeats its
 * accounting check so a number is never read off a document that has drifted.
 *
 * Operations are recognised by their backticked name, exactly as
 * `prove-roles-cover-the-api.php` recognises them: that is how the document
 * writes them, and one mentioned without backticks is one a reader cannot
 * search for.
 *
 *     php tools/count-role-coverage.php
 */

$root = dirname(__DIR__);

// --- What the contract declares ----------------------------------------------

try {
    /** @var array<string, mixed> $contract */
    $contract = json_decode(
        (string) file_get_contents($root . '/openapi.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
} catch (JsonException $e) {
    fwrite(STDERR, 'FAIL: openapi.json is not valid JSON — ' . $e->getMessage() . "\n");

    exit(1);
}

/** @var list<string> $declared */
$declared = [];

/** @var array<string, mixed> $paths */
$paths = is_array($contract['paths'] ?? null) ? $contract['paths'] : [];

foreach ($paths as $methods) {
    if (!is_array($methods)) {
        continue;
    }

    foreach ($methods as $operation) {
        if (is_array($operation) && is_string($operation['operationId'] ?? null)) {
            $declared[] = $operation['operationId'];
        }
    }
}

$declared = array_values(array_unique($declared));

// --- The document, split at its chapter rules ---------------------------------

$document = (string) file_get_contents($root . '/docs/three-roles-end-to-end.md');

/**
 * The chapters, by the `# ` heading that opens each.
 *
 * Matched on the number rather than the title: the titles are prose and will
 * be rewritten, and a split that broke when somebody improved a sentence would
 * teach people not to improve sentences.
 */
$chapters = [
    'USER' => '/^# 1\. /m',
    'TENANT_ADMIN' => '/^# 2\. /m',
    'PLATFORM_ADMIN' => '/^# 3\. /m',
    'NOBODY' => '/^# Ce qu\'aucun/m',
    'END' => '/^# L\'arithm/m',
];

$at = [];

foreach ($chapters as $name => $pattern) {
    if (preg_match($pattern, $document, $found, PREG_OFFSET_CAPTURE) !== 1) {
        fwrite(STDERR, "FAIL: the chapter {$name} was not found. Has the document been restructured?\n");

        exit(1);
    }

    $at[$name] = $found[0][1];
}

/**
 * The operations named in one chapter.
 *
 * A name is only counted when the contract declares it, so a backticked
 * permission, table or column in the same prose is not mistaken for one.
 *
 * @param list<string> $declared
 *
 * @return list<string>
 */
$namedIn = static function (string $text, array $declared): array {
    preg_match_all('/`([A-Za-z][A-Za-z0-9_]*)`/', $text, $found);

    /** @var list<string> $words */
    $words = $found[1];

    return array_values(array_unique(array_intersect($words, $declared)));
};

$user = $namedIn(substr($document, $at['USER'], $at['TENANT_ADMIN'] - $at['USER']), $declared);
$admin = $namedIn(substr($document, $at['TENANT_ADMIN'], $at['PLATFORM_ADMIN'] - $at['TENANT_ADMIN']), $declared);
$platform = $namedIn(substr($document, $at['PLATFORM_ADMIN'], $at['NOBODY'] - $at['PLATFORM_ADMIN']), $declared);
$nobody = $namedIn(substr($document, $at['NOBODY'], $at['END'] - $at['NOBODY']), $declared);

$shared = array_intersect($user, $admin);

$tenantSide = array_unique(array_merge($user, $admin));
$accounted = array_unique(array_merge($tenantSide, $platform, $nobody));
$unaccounted = array_diff($declared, $accounted);

// --- What to read off ---------------------------------------------------------

printf("The contract declares %d operations.\n", count($declared));
printf("This is the number the document, README.md and the published page state.\n\n");

printf("Named in each chapter — what is distinctive about that role, not what it reaches:\n\n");
printf("  USER                   %3d\n", count($user));
printf("  TENANT_ADMIN           %3d\n", count($admin));
printf("  PLATFORM_ADMIN         %3d\n", count($platform));
printf("  nobody, by hand        %3d\n", count($nobody));
printf("  named in two chapters  %3d\n\n", count($shared));

if ($unaccounted !== []) {
    printf("  NOT ACCOUNTED FOR      %3d — %s\n", count($unaccounted), implode(', ', $unaccounted));
    printf("\nRun `composer run gate:roles`; it fails on exactly this.\n");

    exit(1);
}

printf("Every operation is accounted for in some chapter.\n");
