<?php

declare(strict_types=1);

namespace App\Storage\Domain;

use RuntimeException;

/**
 * The store could not do what was asked.
 *
 * A distinct type so a caller can tell "the bytes are not there" from any
 * other failure — and so the message never reaches a client: what a
 * filesystem or an SDK says about a path is exactly the sort of internal
 * detail §31 keeps out of responses.
 */
final class StorageFailure extends RuntimeException
{
}
