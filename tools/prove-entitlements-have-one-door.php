<?php

declare(strict_types=1);

/**
 * Proves entitlement decisions are not made in controllers (R4).
 *
 * §13 forbids rights scattered through the code. `gate:plans` already catches
 * the loud shape of that — `if ($plan === 'PRO')` — but the quiet shape is a
 * controller that injects the entitlement machinery and reasons about it
 * itself. Nothing today does; nothing stopped it either, because Deptrac
 * works in layers and Controller may legitimately reach Domain.
 *
 * There is exactly one door for each question:
 *
 *   "may this request do X?"   RequestContext::has(), resolved once by the
 *                              middleware from capabilitiesFor()
 *   "may this consume more?"   QuotaPolicy::assertMayConsume(), called by the
 *                              service that does the consuming
 *   "what does this tenant     TenantEntitlements, which reports rather than
 *    have?"                    decides
 *
 * A controller reaching past those is re-deciding something already decided,
 * in a place where it will be decided differently next time.
 *
 * Reading `$context->capabilities` is fine — MeController and
 * MyEntitlementsController serve it, which is the point of resolving it.
 * Searching it by hand is not: that is `has()` rewritten, and the rewrite is
 * where the drift starts.
 *
 * Exit 0 means every entitlement question still goes through its own door.
 */

$root = dirname(__DIR__);
$src = $root . '/src';

$ports = ['EntitlementRepository', 'QuotaPolicy', 'UsageMeter'];

$patterns = [
    // The machinery itself, named anywhere in a controller.
    '/\b(?:' . implode('|', $ports) . ')\b/',
    // capabilitiesFor() and assertMayConsume() are the two calls that decide.
    '/->(?:capabilitiesFor|assertMayConsume)\s*\(/',
    // has() rewritten by hand over the resolved list.
    '/\b(?:in_array|array_search|array_intersect|array_diff)\s*\([^;]*capabilities\b/',
    '/\bcapabilities\b[^;]*\b(?:in_array|array_search|array_intersect|array_diff)\s*\(/',
];

$offences = [];

/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();

    // Controllers only. The ports exist to be used — by the middleware that
    // resolves capabilities once, and by the services that enforce quotas
    // where the consuming happens.
    if (!str_contains(str_replace('\\', '/', $path), '/Controller/')) {
        continue;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        continue;
    }

    foreach (explode("\n", $contents) as $number => $line) {
        $trimmed = ltrim($line);

        // Comments explain the rule; they do not break it.
        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $offences[] = sprintf(
                    '%s:%d  %s',
                    ltrim(str_replace($root, '', $path), '/'),
                    $number + 1,
                    trim($line),
                );

                break;
            }
        }
    }
}

if ($offences !== []) {
    fwrite(STDERR, "FAIL: a controller is deciding an entitlement question itself (R4, §13).\n");
    fwrite(STDERR, "Ask RequestContext::has(), or let the service that consumes call QuotaPolicy.\n\n");

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    exit(1);
}

echo "OK: entitlement decisions stay behind their one door.\n";
exit(0);
