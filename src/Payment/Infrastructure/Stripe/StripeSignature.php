<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Stripe;

use App\Shared\Exceptions\UnauthenticatedException;

/**
 * Stripe's webhook signature, verified by hand.
 *
 * `Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>…]`, where each `v1` is an
 * HMAC-SHA256 over `"<t>.<raw body>"` with the endpoint's `whsec_…`. Several
 * `v1` appear while a secret is being rotated; any one matching is enough.
 *
 * Written out rather than delegated to the SDK's `Webhook::constructEvent`,
 * deliberately: this is the thirty lines that decide whether money moves on
 * somebody else's say-so, and they must be readable without opening a
 * vendor directory. The SDK's parsing is used afterwards, for the event
 * *shape*; nothing about authenticity is.
 *
 * Absent, malformed, stale and wrong are one refusal. None of them is a
 * distinction a caller should be able to learn from the response.
 */
final class StripeSignature
{
    public const HEADER = 'Stripe-Signature';

    /** Stripe's own default: a replay window of five minutes. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * @throws UnauthenticatedException
     */
    public static function verify(string $rawBody, ?string $header, string $secret, int $now): void
    {
        if ($header === null || $secret === '') {
            throw new UnauthenticatedException();
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new UnauthenticatedException();
        }

        // The replay window, which the stub's scheme does not have and a real
        // one needs: a captured delivery presented again a day later would
        // otherwise verify perfectly.
        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new UnauthenticatedException();
        }

        // The signed payload is the timestamp and the bytes exactly as
        // received — which is why the port hands the body over raw.
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($signatures as $supplied) {
            // Constant time, so a wrong signature cannot be found a byte at a
            // time by measuring how long the rejection takes.
            if (hash_equals($expected, $supplied)) {
                return;
            }
        }

        throw new UnauthenticatedException();
    }
}
