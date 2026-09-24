<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What a product key may do (ADR-051 §4): the key's permission catalogue,
 * beside the tenant's and the platform's and never converting into either.
 * Three scopes, because a product's server has three things to ask the
 * platform without a person present — and a key holding one of them holds
 * nothing else.
 */
final class ProductScope
{
    public const USAGE_WRITE = 'product.usage.write';
    public const ENTITLEMENTS_READ = 'product.entitlements.read';
    public const MEMBERS_READ = 'product.members.read';

    /**
     * Declaring what this product has built (2026-09-24, ADR-052).
     *
     * The one scope that names no tenant: a product saying what it gates on
     * is saying something about itself. It writes `product_features` and
     * reads nothing, and every code it sends must already be on the
     * platform's list — a program may say what it gates on and may not
     * invent a priced capability.
     */
    public const CAPABILITIES_WRITE = 'product.capabilities.write';

    /** @var list<string> */
    public const ALL = [self::USAGE_WRITE, self::ENTITLEMENTS_READ, self::MEMBERS_READ, self::CAPABILITIES_WRITE];

    public static function isKnown(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }
}
