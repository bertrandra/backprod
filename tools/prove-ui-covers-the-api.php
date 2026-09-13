<?php

declare(strict_types=1);

/**
 * Proves every API operation has somewhere to be reached from.
 *
 * "All the API is exposed in the UI" is the kind of claim that is true on the
 * day it is written and quietly false a month later: an endpoint is added, the
 * contract gate stays green because the contract and the router agree, and
 * nothing anywhere notices that no screen ever calls it. The endpoint then
 * exists, is tested, is documented, and is unreachable by the people it was
 * built for.
 *
 * So the claim is checked rather than asserted. `docs/ui-api-coverage.json`
 * assigns every operation to one of three fates:
 *
 *   - a **screen area**, named and described in `docs/ui-spec.md`;
 *   - **bootstrap**, read to decide what the navigation offers rather
 *     than rendered as a screen of its own;
 *   - **not in the UI**, with a written reason — a webhook has no human
 *     origin, a health probe has no human reader.
 *
 * The check runs both ways, and the second direction matters as much as the
 * first: an operation in the map that no longer exists in the contract is a
 * screen area planning to call something that was renamed or removed, which is
 * how a UI ships a dead button.
 *
 * This gate does not prove a screen was built. It proves no operation was
 * forgotten, which is the part a document cannot keep true on its own.
 *
 * Exit 0 means the contract and the UI map account for each other exactly.
 */

$root = dirname(__DIR__);

$contractPath = $root . '/openapi.json';
$mapPath = $root . '/docs/ui-api-coverage.json';

foreach ([$contractPath, $mapPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, sprintf("FAIL: %s is missing.\n", basename($required)));
        exit(1);
    }
}

/**
 * @return array<string, mixed>
 */
$readJson = static function (string $path): array {
    $raw = file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, sprintf("FAIL: %s could not be read.\n", basename($path)));
        exit(1);
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        fwrite(STDERR, sprintf("FAIL: %s is not valid JSON.\n", basename($path)));
        exit(1);
    }

    return $decoded;
};

$contract = $readJson($contractPath);
$map = $readJson($mapPath);

// --- Every operation the contract declares, by operationId -------------------

$methods = ['get', 'post', 'put', 'patch', 'delete'];

/** @var array<string, string> $operations */
$operations = [];

$paths = $contract['paths'] ?? null;

if (!is_array($paths)) {
    fwrite(STDERR, "FAIL: the contract declares no paths.\n");
    exit(1);
}

foreach ($paths as $path => $item) {
    if (!is_array($item)) {
        continue;
    }

    foreach ($methods as $method) {
        $operation = $item[$method] ?? null;

        if (!is_array($operation)) {
            continue;
        }

        $id = $operation['operationId'] ?? null;

        if (!is_string($id) || $id === '') {
            fwrite(STDERR, sprintf("FAIL: %s %s has no operationId, so it cannot be mapped.\n", strtoupper($method), (string) $path));
            exit(1);
        }

        if (isset($operations[$id])) {
            fwrite(STDERR, sprintf("FAIL: operationId %s is used twice.\n", $id));
            exit(1);
        }

        $operations[$id] = strtoupper($method) . ' ' . (string) $path;
    }
}

// --- Everything the map accounts for, and where -----------------------------

/** @var array<string, string> $claimedBy */
$claimedBy = [];
$duplicates = [];

$claim = static function (string $id, string $owner) use (&$claimedBy, &$duplicates): void {
    if (isset($claimedBy[$id])) {
        $duplicates[] = sprintf('%s claimed by both %s and %s', $id, $claimedBy[$id], $owner);

        return;
    }

    $claimedBy[$id] = $owner;
};

$areas = $map['areas'] ?? [];

if (!is_array($areas) || $areas === []) {
    fwrite(STDERR, "FAIL: the UI map declares no screen areas.\n");
    exit(1);
}

$authorities = $map['authorities'] ?? [];
$authorities = is_array($authorities) ? $authorities : [];

// `$comment` is prose, not an authority. Dropped here rather than tolerated,
// because everything below checks membership of this map: left in, it would
// both inflate the count in the summary and let an area declare
// `"authority": "$comment"` and pass.
unset($authorities['$comment']);

foreach ($areas as $areaId => $area) {
    if (!is_string($areaId) || !is_array($area)) {
        fwrite(STDERR, "FAIL: a screen area is malformed.\n");
        exit(1);
    }

    $authority = $area['authority'] ?? null;

    if (!is_string($authority) || !array_key_exists($authority, $authorities)) {
        fwrite(STDERR, sprintf("FAIL: area %s names authority %s, which is not declared.\n", $areaId, var_export($authority, true)));
        exit(1);
    }

    $areaOperations = $area['operations'] ?? null;

    if (!is_array($areaOperations) || $areaOperations === []) {
        fwrite(STDERR, sprintf("FAIL: area %s claims no operations. An area that calls nothing is not an area.\n", $areaId));
        exit(1);
    }

    foreach ($areaOperations as $id) {
        if (!is_string($id)) {
            fwrite(STDERR, sprintf("FAIL: area %s lists a non-string operation.\n", $areaId));
            exit(1);
        }

        $claim($id, 'area ' . $areaId);
    }
}

$bootstrap = $map['bootstrap']['operations'] ?? [];

if (is_array($bootstrap)) {
    foreach ($bootstrap as $id => $authority) {
        if (!is_string($id)) {
            continue;
        }

        if (!is_string($authority) || !array_key_exists($authority, $authorities)) {
            fwrite(STDERR, sprintf("FAIL: bootstrap operation %s names an undeclared authority.\n", $id));
            exit(1);
        }

        $claim($id, 'bootstrap');
    }
}

$excluded = $map['not_in_ui'] ?? [];

if (is_array($excluded)) {
    foreach ($excluded as $id => $reason) {
        if (!is_string($id)) {
            continue;
        }

        if (!is_string($reason) || trim($reason) === '') {
            // A bare exclusion list is a place to hide an endpoint nobody
            // wanted to build a screen for. The reason is the whole point.
            fwrite(STDERR, sprintf("FAIL: %s is excluded from the UI with no reason given.\n", $id));
            exit(1);
        }

        $claim($id, 'not in the UI');
    }
}

// --- Both directions --------------------------------------------------------

$unmapped = array_keys(array_diff_key($operations, $claimedBy));
$phantom = array_keys(array_diff_key($claimedBy, $operations));

$failed = false;

if ($duplicates !== []) {
    $failed = true;
    fwrite(STDERR, "FAIL: an operation is claimed more than once.\n");
    fwrite(STDERR, "One operation belongs to one area, or the map stops saying where a call comes from.\n\n");

    foreach ($duplicates as $duplicate) {
        fwrite(STDERR, '  ' . $duplicate . "\n");
    }

    fwrite(STDERR, "\n");
}

if ($unmapped !== []) {
    $failed = true;
    sort($unmapped);
    fwrite(STDERR, "FAIL: the contract has operations the UI map does not account for.\n");
    fwrite(STDERR, "Give each one a screen area in docs/ui-api-coverage.json, or list it under\n");
    fwrite(STDERR, "not_in_ui with the reason it has no screen. An endpoint nobody can reach was\n");
    fwrite(STDERR, "built for nobody.\n\n");

    foreach ($unmapped as $id) {
        fwrite(STDERR, sprintf("  %-32s %s\n", $id, $operations[$id]));
    }

    fwrite(STDERR, "\n");
}

if ($phantom !== []) {
    $failed = true;
    sort($phantom);
    fwrite(STDERR, "FAIL: the UI map claims operations the contract does not declare.\n");
    fwrite(STDERR, "Each is a screen planning to call something renamed or removed, which ships as\n");
    fwrite(STDERR, "a dead button.\n\n");

    foreach ($phantom as $id) {
        fwrite(STDERR, sprintf("  %-32s claimed by %s\n", $id, $claimedBy[$id]));
    }

    fwrite(STDERR, "\n");
}

if ($failed) {
    exit(1);
}

$inAreas = 0;

foreach ($claimedBy as $owner) {
    if (str_starts_with($owner, 'area ')) {
        ++$inAreas;
    }
}

printf(
    "OK: all %d operations are accounted for — %d in %d screen areas across %d authorities, %d bootstrap, %d outside the UI with a reason.\n",
    count($operations),
    $inAreas,
    count($areas),
    count($authorities),
    is_array($bootstrap) ? count($bootstrap) : 0,
    is_array($excluded) ? count($excluded) : 0,
);

exit(0);
