<?php

declare(strict_types=1);

/**
 * Proves the architecture gate is live.
 *
 * A quality gate nobody has ever seen fail is an assumption, not a gate. This
 * script runs Deptrac against a fixture that deliberately breaks the
 * Domain → SQL rule of Architecture V2 §37.6 and asserts three things:
 *
 *   1. Deptrac exits non-zero.
 *   2. It names the offending class, so we know it detected the edge rather
 *      than merely failing to start.
 *   3. It names the layer it came from.
 *
 * Exit 0 means the gate is working. Exit 1 means the gate has stopped
 * catching violations and the CI architecture check can no longer be trusted.
 */

$root = dirname(__DIR__);
$binary = $root . '/vendor/bin/deptrac';
$config = $root . '/deptrac.fixture.yaml';

if (!is_file($binary)) {
    fwrite(STDERR, "FAIL: deptrac is not installed (expected {$binary}). Run composer install.\n");
    exit(1);
}

$command = sprintf(
    '%s analyse --config-file=%s --no-progress --no-cache 2>&1',
    escapeshellarg($binary),
    escapeshellarg($config),
);

$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$report = implode("\n", $output);

if ($exitCode === 0) {
    fwrite(STDERR, "FAIL: Deptrac accepted a Domain -> SQL dependency. The architecture gate is not enforcing §37.6.\n");
    fwrite(STDERR, $report . "\n");
    exit(1);
}

foreach (['IllegalPersistence', 'Domain'] as $expected) {
    if (!str_contains($report, $expected)) {
        fwrite(STDERR, "FAIL: Deptrac failed, but its report never mentions '{$expected}'.\n");
        fwrite(STDERR, "This looks like a configuration error rather than a detected violation:\n");
        fwrite(STDERR, $report . "\n");
        exit(1);
    }
}

echo "OK: the architecture gate rejects Domain -> SQL as required by §37.6.\n";
exit(0);
