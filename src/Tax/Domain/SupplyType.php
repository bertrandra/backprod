<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * What is being supplied. It changes the place of taxation, so it cannot be
 * assumed: digital services to a consumer are taxed where the consumer is,
 * which is the whole basis of OSS.
 */
final class SupplyType
{
    public const GOODS = 'GOODS';
    public const SERVICES = 'SERVICES';
    public const DIGITAL_SERVICES = 'DIGITAL_SERVICES';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::GOODS, self::SERVICES, self::DIGITAL_SERVICES];
    }

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
