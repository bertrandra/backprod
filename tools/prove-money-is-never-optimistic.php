<?php

declare(strict_types=1);

/**
 * Proves that no mutation touching money is optimistic.
 *
 * U3 established where optimism is safe: a change that creates nothing anybody
 * can act on. A chat message qualifies — it has no legal number, allocates no
 * sequence the client controls, and nothing is lost if it is taken back.
 *
 * Money does not qualify, and U6 says so outright: *"No optimistic mutation
 * anywhere in this milestone. Issuing an invoice allocates a gapless legal
 * number; a screen that assumes success has invented a document."* The number it
 * invents either collides with a real one or leaves a hole in a sequence that is
 * not allowed to have one, and neither is discoverable from the screen that did
 * it.
 *
 * That rule was a paragraph in a docblock, which is the same kind of promise the
 * six wrong permission codes in U1 were. This makes it mechanical.
 *
 * **What it checks.** `onMutate` is TanStack Query's optimistic hook — the one
 * that writes to the cache before the server has answered. In the modules that
 * carry money it must not appear at all. Not guarded, not conditional: absent.
 *
 * **What it deliberately does not check.** `queries/conversations.ts` uses
 * `onMutate` and should: posting a message is the case optimism is *for*. The
 * scope below is the statement — these files, and not the rest.
 *
 * **What it cannot catch.** A screen that renders an invented number from its
 * own component state, without going through the query cache at all. That needs
 * a rule about what a component may render, which is a bigger check than this
 * one and not built. This proves the cache is never written ahead of the server
 * in the modules that matter.
 *
 * Exit 0 means no money mutation is optimistic.
 */

$root = dirname(__DIR__);

/**
 * The modules where a mutation moves money or allocates a legal document.
 *
 * Adding a new one here is part of adding a new money surface — a file that
 * belongs in this list and is not in it is unchecked, which is why the list is
 * asserted against the directory below rather than trusted.
 */
$guarded = [
    'frontend/src/queries/billing.ts',
    'frontend/src/queries/payments.ts',
    'frontend/src/queries/subscription.ts',
    'frontend/src/queries/einvoicing.ts',
    'frontend/src/queries/checkout.ts',
    'frontend/src/queries/sales.ts',
    // U7. Closing a VAT period is the strongest case in the list: the
    // declaration's figures are computed at closure and this client cannot
    // know them, so an optimistic write would not be a stale number but an
    // invented filing — and the period cannot be reopened to correct it.
    'frontend/src/queries/tax.ts',
    // ADR-043. This module gained the act that *sets* a price and the act that
    // puts one on sale: publishing an offer version freezes it (ADR-033), and
    // every quote, order and subscription written afterwards prices from it.
    // An optimistic write here would show a price as on sale before the
    // database had agreed — and the database is the thing that refuses two
    // versions on sale across the same window.
    //
    // The gate found this file itself, which is the whole point of it
    // comparing the list against what the directory contains.
    'frontend/src/queries/staff.ts',
];

$missing = [];

foreach ($guarded as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $missing[] = $relative;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "FAIL: this gate names files that do not exist:\n\n");

    foreach ($missing as $relative) {
        fwrite(STDERR, sprintf("  %s\n", $relative));
    }

    fwrite(STDERR, "\nA renamed module silently stops being checked. Update the list.\n");
    exit(1);
}

$offenders = [];

foreach ($guarded as $relative) {
    $source = file_get_contents($root . '/' . $relative);

    if ($source === false) {
        fwrite(STDERR, sprintf("FAIL: %s could not be read.\n", $relative));
        exit(1);
    }

    // Line by line, so the message can say where — and so a mention inside a
    // docblock explaining why there is none does not count as one.
    foreach (explode("\n", $source) as $number => $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
            continue;
        }

        if (preg_match('/\bonMutate\b/', $line) === 1) {
            $offenders[] = sprintf('%s:%d', $relative, $number + 1);
        }
    }
}

if ($offenders !== []) {
    fwrite(STDERR, "FAIL: a mutation that moves money is optimistic.\n\n");

    foreach ($offenders as $where) {
        fwrite(STDERR, sprintf("  %s\n", $where));
    }

    fwrite(STDERR, "\n`onMutate` writes to the cache before the server has answered. Where money\n");
    fwrite(STDERR, "is concerned that means showing a document that may not exist — an invoice\n");
    fwrite(STDERR, "number that was never allocated, a payment that never happened.\n\n");
    fwrite(STDERR, "Optimism belongs where nothing binding is created: see queries/conversations.ts,\n");
    fwrite(STDERR, "which is deliberately outside this gate's scope.\n");

    exit(1);
}

printf(
    "OK: none of the %d money modules writes to the cache before the server answers.\n",
    count($guarded),
);

// --- Anything that looks like a money module and is not guarded ---------------
//
// The list above is only as good as its completeness, and a new `queries/*.ts`
// full of `Money` that nobody added is exactly the gap this gate exists to
// close.

$queries = glob($root . '/frontend/src/queries/*.ts');
$unguarded = [];

foreach ($queries === false ? [] : $queries as $path) {
    $relative = str_replace($root . '/', '', $path);

    if (in_array($relative, $guarded, true) || str_ends_with($relative, '.test.ts')) {
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    // Money is integer minor units in this contract, so a module that mentions
    // it is a module that touches money.
    if (preg_match('/minor_units|Schemas\[.Money.\]/', $source) === 1) {
        $unguarded[] = $relative;
    }
}

if ($unguarded !== []) {
    fwrite(STDERR, "\nFAIL: a module handles money and is not guarded by this gate.\n\n");

    foreach ($unguarded as $relative) {
        fwrite(STDERR, sprintf("  %s\n", $relative));
    }

    fwrite(STDERR, "\nAdd it to the list in this file, or the rule stops applying the moment\n");
    fwrite(STDERR, "somebody adds a screen.\n");

    exit(1);
}

exit(0);
