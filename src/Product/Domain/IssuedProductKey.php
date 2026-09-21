<?php

declare(strict_types=1);

namespace App\Product\Domain;

use SensitiveParameter;

/**
 * A key the moment it is issued: the record, and the one time the secret is
 * in the clear. `bearer` is what the product's server puts in the header —
 * `bpk_<key id>_<secret>` — and the platform can never say it again.
 */
final class IssuedProductKey
{
    public function __construct(
        public readonly ProductKey $key,
        #[SensitiveParameter] public readonly string $bearer,
    ) {
    }
}
