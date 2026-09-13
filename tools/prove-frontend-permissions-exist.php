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
 * Since U4 it checks **capabilities** the same way, against the
 * `const CAPABILITY` a route class declares. `gis.access` is why: it looks like
 * a permission, contains a dot, and is not one — and a misspelt capability
 * hides a screen just as quietly.
 *
 * Exit 0 means every permission and every capability the frontend believes in
 * exists.
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

// --- Which authority a navigation entry answers to ---------------------------
//
// **This section is what replaced two navigation trees.**
//
// The console used to live behind its own shell with its own array of entries,
// and "a tenant permission can never reveal a platform screen" was true because
// of where the file sat. One navigation made that implicit guarantee unavailable,
// so every entry now declares a `scope`, and the filter consults that authority
// and no other.
//
// A declaration nobody checks is a comment. This checks it: a `platform` entry
// must name a code from `platform_permissions`, and a `tenant` entry one from
// `permissions`. The two catalogues are separate tables and a code in the wrong
// one is a screen shown to the wrong person — the exact failure the two shells
// were protecting against, now caught by name instead of by file layout.

$catalogue = ['tenant' => [], 'platform' => []];

foreach ($filesIn($root . '/migrations', '.php') as $path) {
    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    // Split on the two INSERTs so each code is attributed to the table it was
    // actually inserted into, rather than guessed from its prefix. `admin.*` is
    // a platform code and looks like neither `staff.` nor a tenant one, which is
    // exactly why guessing would be wrong.
    $segments = preg_split('/INSERT\s+INTO\s+(platform_permissions|permissions)\b/i', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

    if ($segments === false) {
        continue;
    }

    for ($i = 1; $i < count($segments); $i += 2) {
        $table = strtolower($segments[$i]);
        $body = $segments[$i + 1] ?? '';

        // Stop at the end of the statement so the next one's codes are not
        // swept into this table.
        $end = strpos($body, 'SQL');
        $body = $end === false ? $body : substr($body, 0, $end);

        if (preg_match_all("/'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/", $body, $codes) === false) {
            continue;
        }

        $scope = $table === 'platform_permissions' ? 'platform' : 'tenant';

        foreach ($codes[1] as $code) {
            $catalogue[$scope][$code] = true;
        }
    }
}

if ($catalogue['platform'] === [] || $catalogue['tenant'] === []) {
    fwrite(STDERR, "FAIL: one of the two permission catalogues came back empty, which cannot be right.\n");
    exit(1);
}

$navigation = file_get_contents($frontend . '/app/frame/navigation.ts');

if ($navigation === false) {
    fwrite(STDERR, "FAIL: the navigation table could not be read.\n");
    exit(1);
}

// One chunk per entry, split *before each `id:`* rather than before each brace.
//
// The first version split on `{` followed by `id:` and silently read 34 of 35
// entries: one of them carries a comment between the brace and the id, so its
// chunk merged into its neighbour's and its scope was never checked. A gate that
// skips an entry without saying so is worse than no gate, which is why the count
// below is asserted against the number of permissions in the file.
$chunks = preg_split("/(?=\\bid:\\s*')/", $navigation);
$mismatched = [];
$entries = 0;

foreach ($chunks === false ? [] : $chunks as $chunk) {
    if (preg_match("/^id:\\s*'([a-z0-9-]+)'/", $chunk, $id) !== 1) {
        continue;
    }

    if (
        preg_match("/scope:\\s*'(tenant|platform)'/", $chunk, $scope) !== 1
        || preg_match("/permission:\\s*'([a-z][a-z_]*(?:\\.[a-z][a-z_]*)+)'/", $chunk, $code) !== 1
    ) {
        continue;
    }

    ++$entries;

    if (!isset($catalogue[$scope[1]][$code[1]])) {
        $mismatched[$id[1]] = $scope[1] . ' / ' . $code[1];
    }
}

// Every `permission:` in the navigation table belongs to an entry, so the two
// counts have to agree. They disagreed once, quietly.
$declared = preg_match_all("/permission:\\s*'/", $navigation, $ignored);

if ($entries === 0 || $declared === false || $entries !== $declared) {
    fwrite(STDERR, sprintf(
        "FAIL: read %d scoped navigation entries but the table declares %d permissions.\n",
        $entries,
        $declared === false ? -1 : $declared,
    ));
    fwrite(STDERR, "An entry this gate cannot read is an entry it is not checking.\n");
    exit(1);
}

if ($mismatched !== []) {
    ksort($mismatched);

    fwrite(STDERR, "FAIL: a navigation entry names an authority its permission does not belong to.\n");
    fwrite(STDERR, "The scope decides which permission set is consulted, so a mismatch either hides\n");
    fwrite(STDERR, "a screen from everybody or offers it to the wrong person.\n\n");

    foreach ($mismatched as $id => $what) {
        fwrite(STDERR, sprintf("  %-22s declared %s\n", $id, $what));
    }

    exit(1);
}

printf(
    "OK: all %d navigation entries name an authority their permission belongs to (%d tenant, %d platform permissions defined).%s",
    $entries,
    count($catalogue['tenant']),
    count($catalogue['platform']),
    PHP_EOL,
);

// --- Capabilities ------------------------------------------------------------
//
// The same hole, one level over. A capability is not a permission — it says what
// the tenant's plan includes rather than what a role may do — and until U4 the
// frontend used exactly one (`white_label`), so nothing checked them.
//
// `gis.access` made that worth closing: it *looks* like a permission, contains a
// dot, and is not one. A misspelt capability hides a screen just as quietly as a
// misspelt permission, and with two of them in the codebase the next one is
// somebody's guess.
//
// The backend's list is authoritative and small: a route class declares
// `const CAPABILITY = '…'` and requires it. That is where a capability comes
// into existence, the way a migration is where a permission does.

$capabilities = [];

foreach ($filesIn($root . '/src', '.php') as $path) {
    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    if (preg_match_all("/const\s+CAPABILITY\s*=\s*'([a-z][a-z_]*(?:\.[a-z][a-z_]*)*)'/", $source, $matches) === false) {
        continue;
    }

    foreach ($matches[1] as $code) {
        $capabilities[$code] = true;
    }
}

$claimedCapabilities = [];

foreach ($sources as $path) {
    if (str_contains($path, '/api/generated/')) {
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false) {
        continue;
    }

    // Three shapes: the call itself, the third argument of `canAndEntitled`, and
    // a named constant. The constant matters because a screen reads better with
    // `GIS_CAPABILITY` than with a bare string, and a gate that only understood
    // literals would quietly stop checking the moment somebody named one.
    $capabilityPatterns = [
        "/isEntitled\([^,)]*,\s*'([a-z][a-z_.]*)'/",
        "/canAndEntitled\([^,)]*,[^,)]*,\s*'([a-z][a-z_.]*)'/",
        "/[A-Z][A-Z_]*CAPABILITY\s*=\s*'([a-z][a-z_.]*)'/",
    ];

    foreach ($capabilityPatterns as $pattern) {
        if (preg_match_all($pattern, $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $code) {
            $claimedCapabilities[$code][] = str_replace($root . '/', '', $path);
        }
    }
}

$unknownCapabilities = [];

foreach ($claimedCapabilities as $code => $where) {
    if (!isset($capabilities[$code])) {
        $unknownCapabilities[$code] = array_values(array_unique($where));
    }
}

if ($unknownCapabilities !== []) {
    ksort($unknownCapabilities);

    fwrite(STDERR, "FAIL: the frontend gates on capabilities the platform does not define.\n");
    fwrite(STDERR, "No plan can grant these, so whatever they gate can never appear.\n\n");

    foreach ($unknownCapabilities as $code => $where) {
        fwrite(STDERR, sprintf("  %-28s %s\n", $code, implode(', ', $where)));
    }

    fwrite(STDERR, "\nCapabilities are declared as `const CAPABILITY` on the route class that\n");
    fwrite(STDERR, "requires them. Correct the frontend, or add the capability to the platform.\n");

    exit(1);
}

$unused = array_values(array_diff(array_keys($defined), array_keys($referenced)));
sort($unused);

printf(
    "OK: all %d permissions the frontend gates on exist (%d defined by the platform).\n",
    count($referenced),
    count($defined),
);

printf(
    "OK: all %d capabilities the frontend gates on exist (%d declared by the platform).\n",
    count($claimedCapabilities),
    count($capabilities),
);

if ($unused !== []) {
    // Not a failure. Most permissions gate an action rather than a screen.
    printf("     %d defined but not gated on in the frontend: %s\n", count($unused), implode(', ', $unused));
}

exit(0);
