<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/bin',
        __DIR__ . '/migrations',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/public',
    ])
    ->append([
        __DIR__ . '/tools/prove-architecture-gate.php',
        __DIR__ . '/tools/prove-no-product-branching.php',
        __DIR__ . '/tools/prove-no-plan-branching.php',
        __DIR__ . '/tools/prove-entitlements-have-one-door.php',
        __DIR__ . '/tools/prove-openapi-covers-the-api.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        // Group classes first, then functions, then constants — each alphabetical.
        // Without an explicit order the fixer interleaves `use function` lines
        // among the class imports, which is valid but unreadable.
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'single_quote' => true,
    ])
    ->setFinder($finder);
