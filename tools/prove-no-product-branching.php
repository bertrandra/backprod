<?php

declare(strict_types=1);

/**
 * Proves no code branches on a product's identity.
 *
 * §12.1 and CLAUDE.md both forbid this shape:
 *
 *     if ($product === 'product-a') { ... }
 *
 * because it is how a shared platform quietly becomes one product's backend
 * with others bolted on. A new product must be addable with configuration
 * and modules, which is what the product registry is for.
 *
 * This is the mechanical half of that rule: the architecture gate cannot see
 * a string comparison, so it is checked here and run in CI beside the others.
 *
 * Exit 0 means no branching found.
 */

$root = dirname(__DIR__);
$src = $root . '/src';

// Comparing a variable or property whose name mentions a product against a
// string literal. Deliberately narrow: it should catch the banned shape
// without flagging every string comparison in the codebase.
$patterns = [
    '/\$\w*product\w*\s*(?:===|==|!==|!=)\s*[\'"]/i',
    '/[\'"]\s*(?:===|==|!==|!=)\s*\$\w*product\w*\b/i',
    '/->\s*code\s*(?:===|==|!==|!=)\s*[\'"]/i',
    '/\bin_array\s*\(\s*\$\w*product\w*\b/i',
    '/\bmatch\s*\(\s*\$\w*product\w*(?:->code)?\s*\)/i',
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
    fwrite(STDERR, "FAIL: code branches on product identity, which §12.1 forbids.\n");
    fwrite(STDERR, "A new product must be addable with configuration and modules.\n\n");

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    exit(1);
}

echo "OK: no code branches on product identity.\n";
exit(0);
