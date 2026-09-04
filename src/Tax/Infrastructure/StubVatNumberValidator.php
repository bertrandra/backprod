<?php

declare(strict_types=1);

namespace App\Tax\Infrastructure;

use App\Tax\Domain\VatNumberCheck;
use App\Tax\Domain\VatNumberValidator;

/**
 * A verification service that is honestly not VIES.
 *
 * It exists so the whole fiscal path — verify, record the evidence, grant or
 * refuse reverse charge — is exercised end to end without reaching the real
 * service, and so the first real adapter has a worked example.
 *
 * It is named "stub" in the stored evidence, so a verification it produced
 * can never be mistaken for a real one. That matters more here than for
 * payments: a fake verification record would otherwise look like the audit
 * evidence that justifies invoicing at zero.
 *
 * Its rules are deliberately mechanical and include the outage case, because
 * the fail-closed behaviour is the part most worth being able to test:
 *
 *     ending in 0    the service is unreachable   → UNAVAILABLE
 *     ending in 9    a well-formed unknown number → INVALID
 *     otherwise      → VALID
 */
final class StubVatNumberValidator implements VatNumberValidator
{
    public const NAME = 'stub';

    public function check(string $vatNumber): VatNumberCheck
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');

        $outcome = match (substr($normalised, -1)) {
            '0' => VatNumberCheck::UNAVAILABLE,
            '9' => VatNumberCheck::INVALID,
            default => VatNumberCheck::VALID,
        };

        return new VatNumberCheck(
            $outcome,
            $outcome === VatNumberCheck::VALID ? 'Stub Registered Trader' : null,
            $outcome === VatNumberCheck::VALID ? 'Stub address' : null,
            [
                'provider' => self::NAME,
                'queried' => $normalised,
                'checked_at' => gmdate('c'),
            ],
        );
    }
}
