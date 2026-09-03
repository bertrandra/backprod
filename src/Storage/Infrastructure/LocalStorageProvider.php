<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Domain\StorageFailure;
use App\Storage\Domain\StorageProvider;

/**
 * Objects on the local filesystem.
 *
 * The adapter the deployment target actually permits, and the one the stub
 * providers elsewhere are modelled on: real enough to run, narrow enough to
 * replace. Swapping in S3 means writing another StorageProvider and changing
 * a line of wiring.
 *
 * Keys are validated even though this platform generates them. The guard is
 * not there because a caller is expected to pass `../../etc/passwd` — it is
 * there because the day somebody builds a key from something a user typed,
 * this is the code that has to refuse it, and a provider that trusted its
 * caller would instead write the file.
 *
 * Objects are fanned out into two levels of subdirectory by the key's own
 * first characters. A single directory with a hundred thousand entries is
 * slow to list and, on some filesystems, slow to open.
 */
final class LocalStorageProvider implements StorageProvider
{
    /** Keys this platform generates: hex, safely a path segment. */
    private const KEY_PATTERN = '/^[a-f0-9]{32,64}$/';

    public function __construct(private readonly string $root)
    {
    }

    public function put(string $key, string $contents): void
    {
        $path = $this->pathFor($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new StorageFailure('Could not create the storage directory.');
        }

        // Written to a neighbouring temporary file and moved into place, so a
        // reader never sees a half-written object and a crash mid-write
        // leaves the previous one intact. rename() is atomic within a
        // filesystem, which is why the temporary file is beside the target
        // rather than in the system temp directory.
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.part';

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new StorageFailure('Could not write the object.');
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageFailure('Could not move the object into place.');
        }
    }

    public function get(string $key): string
    {
        $contents = @file_get_contents($this->pathFor($key));

        if ($contents === false) {
            throw new StorageFailure('The object could not be read.');
        }

        return $contents;
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);

        if (is_file($path) && !unlink($path)) {
            throw new StorageFailure('The object could not be removed.');
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    private function pathFor(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            // Refused before any path is built. A key that is not the shape
            // this platform generates cannot become a path at all, so there
            // is nothing for a traversal sequence to survive in.
            throw new StorageFailure('Refusing a storage key that is not well formed.');
        }

        return sprintf(
            '%s/%s/%s/%s',
            rtrim($this->root, '/'),
            substr($key, 0, 2),
            substr($key, 2, 2),
            $key,
        );
    }
}
