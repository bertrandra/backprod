<?php

declare(strict_types=1);

/**
 * Proves no code branches on a plan, offer or tier name.
 *
 * §13 is explicit about the shape it forbids:
 *
 *     if ($plan === 'PRO') { ... }
 *
 * and about why. Rights scattered as plan-name checks cannot be reasoned
 * about, cannot be changed without finding every one of them, and are wrong
 * the moment a tier is renamed or a customer is given something outside
 * their tier. Authorisation asks about entitlements; the plan is what
 * produced them, not what is checked.
 *
 * This is the mechanical half of that rule. Deptrac cannot see a string
 * comparison, so it is checked here and run in CI beside the others — the
 * same arrangement as the product-branching gate.
 *
 * Exit 0 means no branching found.
 */

$root = dirname(__DIR__);
$src = $root . '/src';

// Two shapes are caught: comparing a plan-ish variable against any string,
// and comparing anything against a known tier name. The second matters
// because the variable is not always called $plan — `$subscription->offer
// ->code === 'PRO'` is the same defect wearing a different name.
$tiers = 'FREE|PRO|BUSINESS|ENTERPRISE|PREMIUM|STARTER|TRIAL';

$patterns = [
    '/\$\w*(?:plan|offer|tier|subscription)\w*(?:->\w+)*\s*(?:===|==|!==|!=)\s*[\'"]/i',
    '/[\'"]\s*(?:===|==|!==|!=)\s*\$\w*(?:plan|offer|tier|subscription)\w*\b/i',
    '/(?:===|==|!==|!=)\s*[\'"](?:' . $tiers . ')[\'"]/',
    '/[\'"](?:' . $tiers . ')[\'"]\s*(?:===|==|!==|!=)/',
    // A tier name as a match arm, a switch case or an array key. Found by
    // watching the gate fail: `match ($code) { 'ENTERPRISE' => ... }` is the
    // same defect as `$plan === 'PRO'`, and the first two patterns miss it
    // because the variable is not called $plan. A table in code mapping tier
    // names to rights is itself what §13 asks to be moved into the database.
    '/[\'"](?:' . $tiers . ')[\'"]\s*=>/',
    '/\bcase\s+[\'"](?:' . $tiers . ')[\'"]\s*:/',
    // A plan or tier reached through a property path. Found by watching the
    // gate misfire: it matched `$offer->version->billingPeriod`, which is a
    // term rather than an identity — and in doing so revealed that it keyed
    // on the *variable* name, so `match ($version->plan->code)` would have
    // walked past it.
    '/\bmatch\s*\(\s*\$\w+(?:->\w+)*->(?:plan|tier)\b/i',
    '/->(?:plan|tier)(?:->\w+)*\s*(?:===|==|!==|!=)\s*[\'"]/i',
    '/\bin_array\s*\(\s*\$\w*(?:plan|offer|tier)\w*\b/i',
    '/\bmatch\s*\(\s*\$\w*(?:plan|offer|tier)\w*(?:->\w+)*\s*\)/i',
];

$offences = [];

/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);

    if ($contents === false) {
        continue;
    }

    foreach (explode("\n", $contents) as $number => $line) {
        $trimmed = ltrim($line);

        // Comments describe the ban; they are not violations of it.
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
    fwrite(STDERR, "FAIL: code branches on a plan, offer or tier name, which §13 forbids.\n");
    fwrite(STDERR, "Ask about an entitlement instead — the plan is what produced it, not what is checked.\n\n");

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    exit(1);
}

echo "OK: no code branches on a plan or tier name.\n";
exit(0);
