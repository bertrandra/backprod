<?php

declare(strict_types=1);

/**
 * Proves the frontend gates on permissions the platform actually grants.
 *
 * The frontend decides what to offer from permission strings (§13, non-negotiable
 * #25) — which is right, and which means those strings are load-bearing data. A
 * nav entry naming a permission nobody can hold is not a visible error: the entry
 * is simply never shown, so the feature looks unbuilt. A screen gated on a
 * misspelled permission is worse, because it hides a capability from the people
 * who paid for it.
 *
 * That happened. Six of the sixteen codes in the first navigation table were
 * wrong — `project.read` for `projects.read`, `tenant.read` where `members.read`
 * was meant, three console codes invented from endpoint names — and **every unit
 * test passed**, because those tests exercised the mechanism against fixtures the
 * same file invented. A test that supplies its own vocabulary cannot catch a
 * wrong word.
 *
 * So this compares the two vocabularies:
 *
 *   - what the migrations create, which is what a role can actually hold;
 *   - what the frontend gates on, read out of its source.
 *
 * A frontend code that the platform does not define fails. The reverse — a
 * permission the platform defines and the frontend never mentions — is reported
 * but does not fail: plenty of permissions gate an action rather than a screen,
 * and `billing.manage` needing no nav entry is not a defect.
 *
 * **What this cannot catch, stated plainly:** a *real* permission used for the
 * wrong thing. Of the six original mistakes it catches five; the sixth was
 * `tenant.read` on the members entry, and `tenant.read` exists — it was simply
 * not the permission that endpoint requires. Catching that needs a map from
 * endpoint to required permission, read out of the PHP controllers, which is a
 * bigger check than this one and not built. So this gate proves the frontend's
 * vocabulary is real, not that each word is in the right place.
 *
 * Exit 0 means every permission the frontend believes in exists.
 */

$root = dirname(__DIR__);

$migrations = $root . '/migrations';
$frontend = $root . '/frontend/src';
$frontendE2e = $root . '/frontend/e2e';

if (!is_dir($migrations)) {
    fwrite(STDERR, "FAIL: migrations/ is missing.\n");
    exit(1);
}

if (!is_dir($frontend)) {
    // The frontend is optional to this repository's history: the backend shipped
    // first and this gate should not fail a checkout that predates it.
    echo "OK: no frontend/src to check (nothing gates on a permission yet).\n";
    exit(0);
}

/**
 * @return list<string>
 */
$filesIn = static function (string $directory, string $extension): array {
    $found = [];

    /** @var Iterator<string, SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $path => $file) {
        if ($file->isFile() && str_ends_with($path, $extension)) {
            $found[] = $path;
        }
    }

    sort($found);

    return $found;
};

// --- What the platform grants ------------------------------------------------
//
// Read from the INSERT statements that create them, which is the only place a
// permission comes into existence.

$defined = [];

foreach ($filesIn($migrations, '.php') as $path) {
    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    if (preg_match_all("/\\('([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)',\\s*'/", $source, $matches) === false) {
        continue;
    }

    foreach ($matches[1] as $code) {
        $defined[$code] = true;
    }
}

if ($defined === []) {
    fwrite(STDERR, "FAIL: no permissions found in migrations/, which cannot be right.\n");
    exit(1);
}

// --- What the frontend gates on ----------------------------------------------
//
// `permission: '…'` in a navigation table, and the string handed to `can(…)`.
// Both are the shapes this application uses to gate; a third shape appearing
// later should be added here rather than left unchecked.

$referenced = [];

$sources = array_merge(
    $filesIn($frontend, '.ts'),
    $filesIn($frontend, '.tsx'),
    // End-to-end fixtures too. A test session listing a permission nobody can
    // hold asserts against a world that does not exist — which is how the first
    // six wrong codes survived a green suite.
    is_dir($frontendE2e) ? $filesIn($frontendE2e, '.ts') : [],
);

foreach ($sources as $path) {
    if (str_contains($path, '/api/generated/')) {
        // Generated code gates on nothing.
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    // Two shapes, read separately rather than through one clever pattern that
    // has to guess which it matched. The first version of this tried that and
    // captured a query key called `permissions` as if it were a permission.
    //
    // A code always contains a dot, which is what tells `billing.read` apart
    // from a cache key like ['session', 'permissions'].
    $scalarPatterns = [
        "/permission:\\s*'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/",
        "/\\bcan\\([^,)]*,\\s*'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/",
        "/permission=\\{?'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/",
    ];

    foreach ($scalarPatterns as $pattern) {
        if (preg_match_all($pattern, $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $code) {
            $referenced[$code][] = str_replace($root . '/', '', $path);
        }
    }

    // A `permissions: [...]` fixture. Only the dotted entries inside it count,
    // so an empty list contributes nothing and a cache key contributes nothing.
    if (preg_match_all("/permissions:\\s*\\[([^\\]]*)\\]/", $source, $lists) !== false) {
        foreach ($lists[1] as $list) {
            if (preg_match_all("/'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/", $list, $codes) === false) {
                continue;
            }

            foreach ($codes[1] as $code) {
                $referenced[$code][] = str_replace($root . '/', '', $path);
            }
        }
    }
}

// --- Compare -----------------------------------------------------------------

$unknown = [];

foreach ($referenced as $code => $where) {
    if (!isset($defined[$code])) {
        $unknown[$code] = array_values(array_unique($where));
    }
}

if ($unknown !== []) {
    ksort($unknown);

    fwrite(STDERR, "FAIL: the frontend gates on permissions the platform does not define.\n");
    fwrite(STDERR, "Nobody can hold these, so whatever they gate is invisible — which looks\n");
    fwrite(STDERR, "like an unbuilt feature rather than a bug.\n\n");

    foreach ($unknown as $code => $where) {
        fwrite(STDERR, sprintf("  %-28s %s\n", $code, implode(', ', $where)));
    }

    fwrite(STDERR, "\nThe defined permissions are created in migrations/. Correct the frontend,\n");
    fwrite(STDERR, "or add the permission if it is genuinely missing from the platform.\n");

    exit(1);
}

$unused = array_values(array_diff(array_keys($defined), array_keys($referenced)));
sort($unused);

printf(
    "OK: all %d permissions the frontend gates on exist (%d defined by the platform).\n",
    count($referenced),
    count($defined),
);

if ($unused !== []) {
    // Not a failure. Most permissions gate an action rather than a screen.
    printf("     %d defined but not gated on in the frontend: %s\n", count($unused), implode(', ', $unused));
}

exit(0);
