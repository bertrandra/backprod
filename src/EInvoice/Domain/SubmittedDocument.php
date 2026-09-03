<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

/**
 * What the platform hands back when a document is submitted.
 *
 * The identifier is the thing §25.1 requires be kept: it is how this
 * platform's record and the approved platform's record are matched up when
 * somebody has to prove an invoice was transmitted.
 */
final class SubmittedDocument
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
    ) {
    }
}
