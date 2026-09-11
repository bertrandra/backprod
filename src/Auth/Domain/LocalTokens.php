<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * The `iss` and `aud` a token this platform issued carries.
 *
 * Named constants rather than two string literals repeated in the issuer, the
 * verifier and the container. They are checked on every request, so if the three
 * ever disagreed the symptom would be that every token this deployment mints is
 * rejected by the same deployment — a failure that looks like a signing problem
 * and is a typo.
 *
 * They are not secrets and not a security boundary: the signature is. They exist
 * so a token minted for one deployment is refused by another that happens to
 * share a secret, which is the situation a copied `.env` creates.
 */
final class LocalTokens
{
    public const DEFAULT_ISSUER = 'backprod';
    public const DEFAULT_AUDIENCE = 'backprod-api';
}
