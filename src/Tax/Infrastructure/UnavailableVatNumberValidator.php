<?php

declare(strict_types=1);

namespace App\Tax\Infrastructure;

use App\Tax\Domain\VatNumberCheck;
use App\Tax\Domain\VatNumberValidator;

/**
 * No verification service, said honestly (2026-10-29).
 *
 * What a deployment gets when it has not named one. Every number answers
 * `UNAVAILABLE` — "we asked and got no answer" — so none is ever VERIFIED,
 * no sale is reverse-charged on it, and the customer is told the number is
 * not proved, exactly as when VIES is down. That is §25.3's fail-closed rule
 * applied to the absence of a service rather than to its outage: until today
 * the stub stood here, and production "verified" every number that did not
 * end in 0 or 9.
 *
 * A real VIES adapter replaces this by being named in `VAT_NUMBER_VALIDATOR`.
 */
final class UnavailableVatNumberValidator implements VatNumberValidator
{
    public const NAME = 'none';

    public function check(string $vatNumber): VatNumberCheck
    {
        return new VatNumberCheck(
            VatNumberCheck::UNAVAILABLE,
            null,
            null,
            [
                'provider' => self::NAME,
                'reason' => 'No VAT-number verification service is configured for this deployment.',
                'checked_at' => gmdate('c'),
            ],
            self::NAME,
        );
    }
}
