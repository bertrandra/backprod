<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\LocalCredentialRepository;
use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\TokenIssuer;
use App\Shared\Exceptions\UnauthenticatedException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Signing in, staying signed in, and signing out (U12).
 *
 * The one place that decides whether a credential is good. Everything about how
 * a token is *shaped* is in Infrastructure; everything about what a browser does
 * with it is in the frontend; the rules are here.
 */
final class Sessions
{
    /** Thirty days. A month of not signing in again, and a month is also how long a stolen cookie would last. */
    public const REFRESH_LIFETIME = 2_592_000;

    /**
     * A hash of nothing, for the timing of a miss.
     *
     * When no account matches, `password_verify` still runs — against this. A
     * function that returns early for an unknown address answers measurably
     * faster than one that checks a password, and that difference is a way to
     * enumerate who has an account. bcrypt at the same cost as a real hash makes
     * the two paths take the same time.
     */
    private const TIMING_EQUALISER = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function __construct(
        private readonly LocalCredentialRepository $credentials,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenIssuer $issuer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws UnauthenticatedException when the address or the password is wrong
     */
    public function signIn(string $email, #[SensitiveParameter] string $password): Session
    {
        $credential = $this->credentials->findByEmail($email);

        // Verified even when there is no account, and the result thrown away. See
        // TIMING_EQUALISER: returning early here is what makes "no such user" and
        // "wrong password" distinguishable by a stopwatch.
        $against = $credential === null ? self::TIMING_EQUALISER : $credential->passwordHash;
        $matches = password_verify($password, $against);

        if ($credential === null || !$matches) {
            // One event, one shape. The log says which address was tried because
            // that is what makes a brute-force attempt visible; the *response*
            // says only that it failed.
            $this->logger->info('Sign-in refused', ['email' => $email, 'reason' => $credential === null ? 'no account' : 'wrong password']);

            throw new UnauthenticatedException();
        }

        return $this->start($credential->userId, $credential->authSubject, $credential->email)[0];
    }

    /**
     * Exchanges a refresh token for a new pair, and treats reuse as theft.
     *
     * @throws UnauthenticatedException when the token is unknown, spent or expired
     */
    public function refresh(#[SensitiveParameter] string $rawToken): Session
    {
        $stored = $this->refreshTokens->find($this->hash($rawToken));

        if ($stored === null) {
            throw new UnauthenticatedException();
        }

        if ($stored->revoked) {
            /**
             * A token that was already exchanged is being presented again.
             *
             * Either the legitimate client replayed one — which its own rotation
             * makes unlikely — or somebody else has a copy. The two are
             * indistinguishable from here, and the costs are not symmetric:
             * signing the real person out is an inconvenience, and leaving a
             * thief with a live session is not. So the whole family goes.
             */
            $revoked = $this->refreshTokens->revokeAllFor($stored->userId);

            $this->logger->warning('Refresh token reused; revoked every session for the account', [
                'user_id' => $stored->userId,
                'revoked' => $revoked,
            ]);

            throw new UnauthenticatedException();
        }

        if ($stored->expired) {
            throw new UnauthenticatedException();
        }

        [$session, $issuedId] = $this->start($stored->userId, $stored->authSubject, $stored->email);

        // Revoked *after* the replacement exists, and pointing at it. The chain is
        // then reconstructable from any link, which is what makes the reuse above
        // investigable rather than merely refused.
        $this->refreshTokens->revoke($stored->id, $issuedId);

        return $session;
    }

    /**
     * Ends one session.
     *
     * Never throws. A sign-out is somebody saying "I am done", and answering that
     * with an error because the token had already expired would be a worse
     * outcome than the request they asked for. Unknown token, spent token, no
     * token: all of them end with them signed out.
     */
    public function signOut(#[SensitiveParameter] string $rawToken): void
    {
        if ($rawToken === '') {
            return;
        }

        $stored = $this->refreshTokens->find($this->hash($rawToken));

        if ($stored !== null && !$stored->revoked) {
            $this->refreshTokens->revoke($stored->id, null);
        }
    }

    /**
     * A new pair, and the id of the refresh token in it.
     *
     * The id is returned rather than remembered on the object: a field holding
     * "the last token I issued" is state between two calls, and a service that
     * handles one request per process today would silently chain the wrong tokens
     * the moment anything reused the instance.
     *
     * @return array{Session, string}
     */
    private function start(string $userId, string $authSubject, ?string $email): array
    {
        // 32 bytes from the CSPRNG. The token is the secret; the row holds only
        // its SHA-256, and the database refuses anything that is not one.
        $raw = bin2hex(random_bytes(32));

        $issuedId = $this->refreshTokens->issue($userId, $this->hash($raw), self::REFRESH_LIFETIME);

        return [
            new Session($this->issuer->issue($authSubject, $email), $raw, self::REFRESH_LIFETIME),
            $issuedId,
        ];
    }

    /**
     * SHA-256, not bcrypt.
     *
     * A password needs a slow hash because it is short, guessable and chosen by a
     * person. This token is 256 bits of CSPRNG output, so there is nothing to
     * guess and a slow hash would only make every request slower. What is needed
     * is that the stored value cannot be presented as a credential, and a digest
     * gives that.
     */
    private function hash(#[SensitiveParameter] string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
