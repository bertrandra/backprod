<?php

declare(strict_types=1);

/**
 * Proves the published contract and the running API describe the same thing.
 *
 * CLAUDE.md's per-endpoint loop opens with "Update OpenAPI — contract first,
 * always", and §37.16 will not call a feature done without it. A contract
 * nothing checks drifts from the code within a milestone and is then worse
 * than none, because it is believed.
 *
 * So this compares both directions:
 *
 *   - every route the application registers is described;
 *   - every operation described is actually routed.
 *
 * The second direction matters as much as the first. A path removed from the
 * router and left in the contract is a promise the platform no longer keeps.
 *
 * There is no exemption list. One existed while the 116 endpoints that
 * shipped before the contract were being back-filled, and it was deleted the
 * day it emptied — a list of allowed omissions that nothing is omitting is an
 * invitation to omit something.
 *
 * JSON rather than YAML, and not by preference: there is no ext-yaml here and
 * a lock file that cannot be regenerated offline rules out adding a parser.
 * JSON is a first-class OpenAPI serialization, and `json_decode` is in core.
 *
 * Exit 0 means the contract and the router agree.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// --- What the application actually serves ------------------------------------

$collector = new class (
    new FastRoute\RouteParser\Std(),
    new FastRoute\DataGenerator\GroupCountBased(),
) extends FastRoute\RouteCollector {
    /** @var list<string> */
    public array $seen = [];

    /**
     * The parent types none of these, and narrowing them here would be an
     * override that accepts less than what it replaces.
     *
     * @param string|string[] $httpMethod
     */
    public function addRoute($httpMethod, mixed $route, mixed $handler): void
    {
        foreach ((array) $httpMethod as $method) {
            $this->seen[] = strtoupper((string) $method) . ' ' . (string) $route;
        }

        parent::addRoute($httpMethod, $route, $handler);
    }
};

$routes = require $root . '/config/routes.php';

if (!is_callable($routes)) {
    fwrite(STDERR, "FAIL: config/routes.php does not return a callable.\n");

    exit(1);
}

$routes($collector);
$served = $collector->seen;

// --- What the contract promises ----------------------------------------------

$path = $root . '/openapi.json';

if (!is_file($path)) {
    fwrite(STDERR, "FAIL: openapi.json is missing. The contract comes first.\n");

    exit(1);
}

$raw = file_get_contents($path);

if ($raw === false) {
    fwrite(STDERR, "FAIL: openapi.json could not be read.\n");

    exit(1);
}

try {
    /** @var array<string, mixed> $document */
    $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'FAIL: openapi.json is not valid JSON — ' . $e->getMessage() . "\n");

    exit(1);
}

foreach (['openapi', 'info', 'paths'] as $required) {
    if (!array_key_exists($required, $document)) {
        fwrite(STDERR, sprintf("FAIL: openapi.json has no \"%s\".\n", $required));

        exit(1);
    }
}

$methods = ['get', 'put', 'post', 'delete', 'patch', 'options', 'head', 'trace'];
$documented = [];
$paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

/** @var array<string, mixed> $operations */
foreach ($paths as $route => $operations) {
    if (!is_array($operations)) {
        continue;
    }

    foreach ($operations as $method => $operation) {
        if (in_array((string) $method, $methods, true)) {
            $documented[] = strtoupper((string) $method) . ' ' . (string) $route;
        }
    }
}

// --- The comparison -----------------------------------------------------------

$failures = [];

foreach (array_diff($served, $documented) as $route) {
    $failures[] = sprintf('%s is served but not described.', $route);
}

foreach (array_diff($documented, $served) as $route) {
    $failures[] = sprintf('%s is described but not served — a promise nothing keeps.', $route);
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: the contract and the router disagree.\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, '  ' . $failure . "\n");
    }

    exit(1);
}

printf("OK: the contract describes all %d operations the router serves.\n", count($served));

exit(0);
