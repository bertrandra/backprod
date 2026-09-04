<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * The closed set of §25.3. A regime outside it is a bug, and a bug that
 * reaches a declaration is expensive to unwind.
 */
final class VatRegime
{
    public const STANDARD = 'STANDARD';
    public const REVERSE_CHARGE = 'REVERSE_CHARGE';
    public const OSS = 'OSS';
    public const EXEMPT = 'EXEMPT';
    public const ZERO_RATED = 'ZERO_RATED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STANDARD,
            self::REVERSE_CHARGE,
            self::OSS,
            self::EXEMPT,
            self::ZERO_RATED,
            self::OUT_OF_SCOPE,
        ];
    }

    /**
     * Only two regimes charge VAT. The others are zero for different
     * reasons, and the reason is what the mention on the invoice has to say.
     */
    public static function charges(string $regime): bool
    {
        return $regime === self::STANDARD || $regime === self::OSS;
    }
}
