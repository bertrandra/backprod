<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * How a self-service sign-up ends (2026-09-18), decided by the platform.
 *
 * Somebody who chose an offer on a storefront and created an account is a
 * USER who may buy (ADR-049, amended). Whether the checkout opens there and
 * then, or the person lands in the application first and picks the offer
 * again from the catalogue, is the operator's call — one setting for the
 * whole platform, not one per organisation, because it is about the shape
 * of the front door rather than about any customer.
 *
 * `PAY`: the ordinary authenticated checkout opens on the storefront page,
 * on the session the sign-up issued, with the card form where the secret
 * was born (ADR-048). `CATALOGUE`: the page goes to the organisation's root,
 * which is the member's catalogue, and the same offer is one click away.
 */
interface StorefrontSettings
{
    public const PAY = 'PAY';
    public const CATALOGUE = 'CATALOGUE';
    public const AFTER_SIGN_UP = [self::PAY, self::CATALOGUE];

    public function afterSignUp(): string;

    public function setAfterSignUp(string $choice): void;
}
