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
 * **On the syntax tree, not on lines** (2026-10-26). The first version matched
 * regular expressions line by line, so the same comparison written across
 * three lines (`$context\n->product\n->code\n=== 'plan'`), a switch on a
 * product code, or a key read from an array (`$request['product']`) walked
 * past it. It now parses each file and looks at the comparison itself: an
 * equality, a `match`, a `switch` or an `in_array` with a string literal on
 * one side and, on the other, an expression that names a product — a
 * variable, property, method or array key whose name says `product`, or a
 * `->code` read.
 *
 * **And it is seen failing first.** Before reading `src/`, it reads
 * `tools/gate-fixtures/product-branching/`: every `Offending*.php` there must
 * be reported exactly once and `Allowed.php` never. A gate nobody has seen
 * fail is an assumption — the reason `gate:proof` exists.
 *
 * Exit 0 means no branching found.
 */

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/**
 * Whether an expression names a product: `$product`, `$productCode`,
 * `$this->product`, `$ctx->product()->code()`, `$request['product']`, or any
 * `->code` read — the last kept from the first version of this gate, which
 * flagged every `->code === '…'` and found none.
 */
function namesProduct(Node $expr): bool
{
    $mentions = static fn (mixed $name): bool => $name instanceof Node\Identifier || is_string($name)
        ? stripos((string) $name, 'product') !== false
        : false;

    return match (true) {
        $expr instanceof Expr\Variable => $mentions($expr->name),
        $expr instanceof Expr\PropertyFetch,
        $expr instanceof Expr\NullsafePropertyFetch => $mentions($expr->name)
            || ($expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'code')
            || namesProduct($expr->var),
        $expr instanceof Expr\StaticPropertyFetch => $mentions($expr->name),
        $expr instanceof Expr\MethodCall,
        $expr instanceof Expr\NullsafeMethodCall => $mentions($expr->name)
            || ($expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'code')
            || namesProduct($expr->var),
        $expr instanceof Expr\ArrayDimFetch => ($expr->dim instanceof String_ && $mentions($expr->dim->value))
            || namesProduct($expr->var),
        default => false,
    };
}

/**
 * A literal that names something. An empty string is a presence test, not an
 * identity.
 */
function isNamingLiteral(?Node $expr): bool
{
    return $expr instanceof String_ && $expr->value !== '';
}

/**
 * @return list<array{line: int, what: string}>
 */
function offencesIn(string $source): array
{
    $parser = (new ParserFactory())->createForHostVersion();
    $ast = $parser->parse($source) ?? [];
    $finder = new NodeFinder();
    $found = [];

    foreach ($finder->find($ast, static fn (Node $node): bool => true) as $node) {
        if (
            $node instanceof Expr\BinaryOp\Identical
            || $node instanceof Expr\BinaryOp\NotIdentical
            || $node instanceof Expr\BinaryOp\Equal
            || $node instanceof Expr\BinaryOp\NotEqual
        ) {
            if (
                (isNamingLiteral($node->right) && namesProduct($node->left))
                || (isNamingLiteral($node->left) && namesProduct($node->right))
            ) {
                $found[] = ['line' => $node->getStartLine(), 'what' => 'a product compared with a literal'];
            }

            continue;
        }

        if ($node instanceof Expr\Match_ && namesProduct($node->cond)) {
            foreach ($node->arms as $arm) {
                foreach ($arm->conds ?? [] as $cond) {
                    if (isNamingLiteral($cond)) {
                        $found[] = ['line' => $node->getStartLine(), 'what' => 'a match on a product'];

                        continue 3;
                    }
                }
            }

            continue;
        }

        if ($node instanceof Node\Stmt\Switch_ && namesProduct($node->cond)) {
            foreach ($node->cases as $case) {
                if (isNamingLiteral($case->cond)) {
                    $found[] = ['line' => $node->getStartLine(), 'what' => 'a switch on a product'];

                    continue 2;
                }
            }

            continue;
        }

        if (
            $node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toLowerString() === 'in_array'
            && isset($node->args[0])
            && $node->args[0] instanceof Node\Arg
            && namesProduct($node->args[0]->value)
            // Against a written list of names. `in_array($productId, $assigned)`
            // is membership in data, which is what the rule asks for.
            && isset($node->args[1])
            && $node->args[1] instanceof Node\Arg
            && $node->args[1]->value instanceof Expr\Array_
            && array_filter(
                $node->args[1]->value->items,
                static fn (?Node\ArrayItem $item): bool => $item !== null && isNamingLiteral($item->value),
            ) !== []
        ) {
            $found[] = ['line' => $node->getStartLine(), 'what' => 'in_array on a product'];
        }
    }

    return $found;
}

/**
 * @return list<string> offences, as "path:line  what"
 */
function scan(string $root, string $directory): array
{
    $offences = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        foreach (offencesIn($contents) as $offence) {
            $offences[] = sprintf(
                '%s:%d  %s',
                ltrim(str_replace($root, '', $path), '/'),
                $offence['line'],
                $offence['what'],
            );
        }
    }

    sort($offences);

    return $offences;
}

// --- The gate is seen failing ---------------------------------------------------

$fixtures = $root . '/tools/gate-fixtures/product-branching';
$byFile = [];

foreach (scan($root, $fixtures) as $offence) {
    $file = basename(explode(':', $offence)[0]);
    $byFile[$file] = ($byFile[$file] ?? 0) + 1;
}

$broken = [];
$fixtureFiles = glob($fixtures . '/*.php');

foreach ($fixtureFiles === false ? [] : $fixtureFiles as $path) {
    $file = basename($path);
    $expected = str_starts_with($file, 'Offending') ? 1 : 0;
    $seen = $byFile[$file] ?? 0;

    if ($seen !== $expected) {
        $broken[] = sprintf('  %s: expected %d, reported %d', $file, $expected, $seen);
    }
}

if ($fixtureFiles === false || count($fixtureFiles) < 2 || $broken !== []) {
    fwrite(STDERR, "FAIL: the product-branching gate does not see its own fixtures as it should.\n\n");
    fwrite(STDERR, implode("\n", $broken) . "\n\n");
    fwrite(STDERR, "A shape it no longer reports is a shape src/ can now contain unchecked.\n");

    exit(1);
}

// --- The source ------------------------------------------------------------------

$offences = scan($root, $root . '/src');

if ($offences !== []) {
    fwrite(STDERR, "FAIL: code branches on product identity, which §12.1 forbids.\n");
    fwrite(STDERR, "A new product must be addable with configuration and modules.\n\n");

    foreach ($offences as $offence) {
        fwrite(STDERR, '  ' . $offence . "\n");
    }

    exit(1);
}

printf(
    "OK: no code branches on product identity (and all %d fixtures are judged as written).\n",
    count($fixtureFiles),
);
exit(0);
