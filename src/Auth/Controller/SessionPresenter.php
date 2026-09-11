<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Session;

/**
 * What a sign-in answers with.
 *
 * The refresh token is deliberately **not** here. It leaves in a `Set-Cookie`
 * header and never in a body, because a body is something a script can read and
 * then store somewhere worse. Putting it in both places for convenience would
 * throw away the entire reason the cookie exists.
 *
 * The names are the OAuth 2 ones — `access_token`, `token_type`, `expires_in` —
 * not because anything here implements OAuth, but because every client library
 * and every developer already knows what they mean.
 */
final class SessionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Session $session): array
    {
        return [
            'access_token' => $session->accessToken->token,
            'token_type' => 'Bearer',
            'expires_in' => $session->accessToken->expiresIn,
        ];
    }
}
