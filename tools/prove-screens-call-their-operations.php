<?php

declare(strict_types=1);

/**
 * Proves the frontend **calls** what the coverage map claims it calls.
 *
 * `gate:ui` already checks `ui-api-coverage.json` against `openapi.json` in both
 * directions, and it is explicit about what it cannot do — ui-spec.md §7:
 *
 * > **It does not prove a screen was built.** It proves no operation was
 * > *forgotten* — that every one has a declared home and no home points at
 * > nothing. Whether `billing.einvoicing` has been implemented is a different
 * > question, and this gate will happily pass while it is empty.
 *
 * That sentence describes a hole the size of a milestone: an area could claim
 * seven operations, ship a screen that calls two, and both gates would pass
 * while five endpoints had no caller anywhere. The roadmap put closing it in U9
 * *"the check ui-spec.md §7 names as impossible until a frontend exists — U9 is
 * when it exists"*.
 *
 * **What it checks.**
 *
 *   1. Every area declares at least one `route`, and every route it declares
 *      exists in `frontend/src/app/router.tsx`. An area with no route is a
 *      screen nobody can open.
 *   2. Every operation any area claims is called through the **generated
 *      client** — matched by the method and path the contract gives that
 *      operation, not by its name, so a call that goes to the wrong path fails
 *      even when a function nearby is named after the right one.
 *   3. The reverse: every client call in the frontend belongs to an operation
 *      that some area claims, or to the bootstrap. A call to an operation
 *      the map lists under `not_in_ui` is a violation of the reason written
 *      there — `downloadAsset` says outright that *"the generated client never
 *      fetches these bytes itself"*, and this is what holds that true.
 *
 * **What it cannot prove.** That the call is reachable from the route the area
 * declares, or that a person can trigger it. A query module is shared between
 * areas — `billing.ts` serves invoices, credit notes and the profile — so
 * attributing a call to one area would need a module-per-area rule this
 * codebase deliberately does not have. What this proves is that no operation is
 * claimed and uncalled, and that no route is claimed and absent. The Playwright
 * suites are what prove a person can reach them.
 *
 * Exit 0 means every claim in the map has code behind it.
 */

$root = dirname(__DIR__);

$coverage = json_decode((string) file_get_contents($root . '/docs/ui-api-coverage.json'), true);
$contract = json_decode((string) file_get_contents($root . '/openapi.json'), true);

if (!is_array($coverage) || !is_array($contract)) {
    fwrite(STDERR, "FAIL: could not read the coverage map or the contract.\n");
    exit(1);
}

// --- operationId -> "METHOD /path", from the contract ------------------------
//
// The contract is the authority for where an operation lives. Matching on the
// path rather than on the operation's name is the point: `useInvoices` calling
// `/api/v1/billing/credit-notes` would pass a name-based check and be wrong.

/** @var array<string, string> $where */
$where = [];

foreach ($contract['paths'] as $path => $item) {
    foreach ($item as $method => $operation) {
        if (!is_array($operation) || !isset($operation['operationId'])) {
            continue;
        }

        $where[$operation['operationId']] = strtoupper($method) . ' ' . $path;
    }
}

// --- what the frontend actually calls ---------------------------------------

$sources = [];
$directory = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/frontend/src', FilesystemIterator::SKIP_DOTS),
);

foreach ($directory as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }

    $name = $file->getFilename();

    // Tests stub the client rather than calling it, and a stub naming a path is
    // not a screen reaching for it. Counting them would let a deleted call keep
    // passing because its test still mentioned the path.
    if (!preg_match('/\.tsx?$/', $name) || str_contains($name, '.test.')) {
        continue;
    }

    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
}

/** @var array<string, list<string>> $called */
$called = [];

foreach ($sources as $path => $source) {
    // Both call shapes: `client.GET('/x'` on one line, and the wrapped form
    // where the path is on the next. `\s*` spans the newline.
    preg_match_all(
        "/\bclient\.(GET|POST|PUT|PATCH|DELETE)\(\s*'([^']+)'/",
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as $match) {
        $key = $match[1] . ' ' . $match[2];
        $called[$key][] = str_replace($root . '/', '', $path);
    }
}

$failures = [];

// --- 1. every area has routes, and they exist -------------------------------

$router = (string) file_get_contents($root . '/frontend/src/app/router.tsx');

foreach ($coverage['areas'] as $area => $entry) {
    $routes = $entry['routes'] ?? [];

    if ($routes === []) {
        $failures[] = sprintf('%s declares no route: a screen nobody can open.', $area);
        continue;
    }

    foreach ($routes as $route) {
        // Quoted exactly as the router writes it, so a path that only appears
        // in a comment or a link does not satisfy the claim.
        if (!str_contains($router, "'" . $route . "'")) {
            $failures[] = sprintf('%s claims route %s, which the router does not define.', $area, $route);
        }
    }
}

// --- 2. every claimed operation is called -----------------------------------

$claimed = [];

foreach ($coverage['areas'] as $area => $entry) {
    foreach ($entry['operations'] as $operation) {
        $claimed[$operation] = $area;

        if (!isset($where[$operation])) {
            // gate:ui catches this too; said here rather than crashing below.
            $failures[] = sprintf('%s claims %s, which the contract does not declare.', $area, $operation);
            continue;
        }

        if (!isset($called[$where[$operation]])) {
            $failures[] = sprintf(
                '%s claims %s (%s) and nothing calls it.',
                $area,
                $operation,
                $where[$operation],
            );
        }
    }
}

// --- 3. and nothing calls what no area claims -------------------------------

$bootstrap = array_keys($coverage['bootstrap']['operations']);
$allowed = [];

foreach ([...array_keys($claimed), ...$bootstrap] as $operation) {
    if (isset($where[$operation])) {
        $allowed[$where[$operation]] = $operation;
    }
}

/** @var array<string, string> $byLocation */
$byLocation = [];

foreach ($where as $operation => $location) {
    $byLocation[$location] = $operation;
}

foreach ($called as $location => $files) {
    if (isset($allowed[$location])) {
        continue;
    }

    $operation = $byLocation[$location] ?? null;

    if ($operation === null) {
        $failures[] = sprintf(
            '%s is called in %s and is not an operation the contract declares.',
            $location,
            implode(', ', array_unique($files)),
        );

        continue;
    }

    $reason = $coverage['not_in_ui'][$operation] ?? null;

    $failures[] = $reason === null
        ? sprintf('%s (%s) is called in %s and no area claims it.', $operation, $location, implode(', ', array_unique($files)))
        : sprintf(
            "%s is called in %s, and the coverage map says it has no screen:\n      \"%s\"",
            $operation,
            implode(', ', array_unique($files)),
            $reason,
        );
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: the coverage map claims things the frontend does not do.\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf("  - %s\n", $failure));
    }

    fwrite(STDERR, "\ngate:ui proves the bookkeeping adds up. This proves there is code behind it:\n");
    fwrite(STDERR, "an area with no route is a screen nobody can open, and an operation nobody\n");
    fwrite(STDERR, "calls is an endpoint built for nobody with a screen's name against it.\n");

    exit(1);
}

printf(
    "OK: %d areas each have a route, all %d claimed operations are called through the generated client, and nothing calls what no area claims.\n",
    count($coverage['areas']),
    count($claimed),
);

exit(0);
