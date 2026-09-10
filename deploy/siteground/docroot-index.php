<?php

declare(strict_types=1);

/**
 * The only PHP file in the document root.
 *
 * The application lives *outside* it — one directory up, beside this one — so
 * that no request can reach `src/`, `vendor/`, `migrations/` or `.env` even if
 * every rewrite rule in `.htaccess` were deleted. That is the difference between
 * a secret protected by configuration and one protected by not being there.
 *
 * This file does nothing but hand over. The front controller it requires is the
 * same `public/index.php` that serves the application in development, and every
 * path inside it resolves relative to itself — so the bundle a host runs is the
 * repository, not a rearranged copy of it with its own bugs.
 *
 * If your host refuses to read outside the document root (`open_basedir`), or you
 * put the application somewhere else, this constant is the one line to change.
 */
const BACKPROD_APP = __DIR__ . '/../backprod-app';

$frontController = BACKPROD_APP . '/public/index.php';

if (!is_file($frontController)) {
    // Said plainly, and without a path: whoever sees this is deploying, and the
    // one thing they need to know is which half is missing. A stack trace or an
    // absolute path here would be a disclosure on a page anybody can load.
    http_response_code(500);
    header('Content-Type: application/json');

    echo json_encode([
        'error' => [
            'code' => 'DEPLOYMENT_INCOMPLETE',
            'message' => 'The application directory is missing. Upload it beside the document root, or correct BACKPROD_APP in index.php.',
        ],
    ], JSON_THROW_ON_ERROR);

    exit;
}

require $frontController;
