<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What a visitor wrote through a product's contact form (2026-11-23).
 *
 * **The consent is kept as it was read.** The sentence beside the box the
 * visitor ticked is the product's to change, and a request answered next
 * year must still say what this person agreed to — so it is stored with the
 * request, in the language they read it in, the way an invoice keeps its
 * snapshot.
 *
 * **Nothing is kept that was not typed.** No address, no browser, no
 * referrer: what answering somebody needs is their name, how to reach them
 * and what they asked, and a record kept for no use is a record to lose.
 */
final class ContactRequest
{
    public const LONGEST_NAME = 200;

    public const LONGEST_EMAIL = 254;

    public const LONGEST_MESSAGE = 5_000;

    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $productCode,
        public readonly string $name,
        public readonly string $email,
        public readonly string $message,
        public readonly string $consent,
        public readonly string $locale,
        public readonly string $receivedAt,
        public readonly ?string $handledAt = null,
        public readonly ?string $handledBy = null,
    ) {
    }
}
