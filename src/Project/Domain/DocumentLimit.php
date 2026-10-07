<?php

declare(strict_types=1);

namespace App\Project\Domain;

/**
 * How large a project document may be, decided by the platform's operator
 * (2026-10-07).
 *
 * It was a constant: 1 MiB, then 4 MiB the morning Plan's schema 4 outgrew
 * the first — and each change was a release. The operator wanted to make it
 * from the console's setup menu instead, so it is one platform setting,
 * `staff.products.manage`, the same for every product: a limit that meant one
 * thing for one product and another for the next is what the earlier change
 * deliberately chose not to have.
 *
 * **Absent means the default**, 4 MiB, never "no limit": a missing row that
 * let anything through would put a mesh in PostgreSQL the first time a
 * setting was never written (non-negotiable #9). Bounded both ways — below a
 * megabyte a real site no longer fits, and above 64 MiB a document is an
 * asset whatever it is called, and PHP's own upload ceiling is long past.
 *
 * Counted in whole mebibytes because that is the unit the screen offers and
 * the refusal is read in; nobody chooses 3 999 872 bytes.
 */
interface DocumentLimit
{
    public const DEFAULT_MIB = 4;

    public const MINIMUM_MIB = 1;

    public const MAXIMUM_MIB = 64;

    public const BYTES_PER_MIB = 1024 * 1024;

    /** The limit in force, in whole mebibytes. */
    public function maxDocumentMib(): int;

    /** Within MINIMUM_MIB..MAXIMUM_MIB; the caller has checked. */
    public function setMaxDocumentMib(int $mib): void;
}
