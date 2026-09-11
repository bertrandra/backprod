<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Domain\LocalCredentialRepository;
use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\RegisteredAccount;
use App\Auth\Domain\TokenIssuer;
use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\NotificationRepository;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\UnauthenticatedException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Signing up, signing in, staying signed in, and signing out.
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

    /** Two days to click a link in an email. Long enough for a weekend, short enough to matter. */
    public const VERIFICATION_LIFETIME = 172_800;

    public function __construct(
        private readonly LocalCredentialRepository $credentials,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenIssuer $issuer,
        private readonly LoggerInterface $logger,
        private readonly AccountRegistrar $registrar,
        private readonly NotificationRepository $notifications,
        /**
         * Where this deployment is reachable, for the link in a confirmation
         * email. Empty when nobody configured it, and the link is then
         * relative — which is useless in a mail client and honest about it,
         * where a guessed host would send people to somebody else's site.
         */
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * A stranger becomes a customer: account, organisation, and a session.
     *
     * **It lives here rather than in a service of its own** because signing up
     * ends in exactly what signing in ends in — a token pair from `start()` —
     * and a separate service would either duplicate that or depend on this
     * one, which the layering forbids for good reason.
     *
     * **The session is issued immediately, before the address is confirmed.**
     * That is the decision the storefront was built on: an interrupted
     * purchase is a purchase that does not happen, and a verification link
     * landing in a spam folder is not something to put between somebody and a
     * subscription. `users.email_verified_at` records the doubt for whoever
     * downstream needs to act on it.
     *
     * **The address being taken is answered plainly**, unlike signing in,
     * where `TIMING_EQUALISER` exists precisely so that "no such account"
     * cannot be told from "wrong password". The asymmetry is not an oversight:
     * a person who cannot be told their address is already registered cannot
     * complete the purchase they came for, and the sign-in form is one click
     * away. What bounds the enumeration this permits is the rate limiter,
     * which on a public route is the tighter allowance (§31).
     *
     * @return array{Session, RegisteredAccount}
     *
     * @throws ConflictException when the address already has an account
     */
    public function signUp(
        string $email,
        #[SensitiveParameter] string $password,
        ?string $displayName,
        ?string $organisation,
        string $productCode,
        ?string $countryCode = null,
    ): array {
        if ($this->registrar->emailIsTaken($email)) {
            throw new ConflictException('EMAIL_TAKEN', 'That address already has an account.');
        }

        // B2C and B2B differ by one optional field and nothing else. Somebody
        // buying for themselves has no company to name and must not be made to
        // invent one, so the tenant takes their own name — an invoice still
        // needs somebody to be addressed to, and that is who it is. Neither
        // case is recorded as a *kind* of customer: nothing downstream should
        // branch on it, and a column saying B2C would invite something to.
        $organisationName = $organisation !== null && trim($organisation) !== ''
            ? trim($organisation)
            : ($displayName ?? $email);

        $account = $this->registrar->register(
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $displayName,
            $organisationName,
            $productCode,
            $countryCode,
        );

        $this->askForConfirmation($account);

        return [$this->start($account->userId, $account->authSubject, $account->email)[0], $account];
    }

    /**
     * Confirms an address from the token in the link.
     *
     * Never says why a token failed — unknown, expired and spent are one
     * answer — and never signs anybody in. A link that produced a session
     * would be a credential sitting in an inbox, readable by anybody who ever
     * gains access to it, for as long as the mail is kept.
     */
    public function confirmEmail(#[SensitiveParameter] string $rawToken): bool
    {
        return $rawToken !== '' && $this->registrar->confirmEmail($this->hash($rawToken));
    }

    /**
     * The token, and the notice carrying it.
     *
     * ACCOUNT rather than SECURITY: this is not something that happened to an
     * account somebody already has, which is what the unmutable category is
     * for. A person who switches off account email has decided not to confirm
     * their address, and the platform lets them.
     *
     * The raw token reaches the payload and the hash reaches the row, which is
     * the same split `refresh_tokens` makes: what is stored must not be
     * presentable as a credential.
     */
    private function askForConfirmation(RegisteredAccount $account): void
    {
        $raw = bin2hex(random_bytes(32));

        $this->registrar->issueVerification($account->userId, $this->hash($raw), self::VERIFICATION_LIFETIME);

        $this->notifications->raise(
            $account->tenantId,
            $account->productId,
            $account->userId,
            'account.email_verification',
            Category::ACCOUNT,
            [
                'link' => rtrim($this->appUrl, '/') . '/sign-in?verify=' . $raw,
                'email' => $account->email,
            ],
            // One live token per account, so one notice per account: asking
            // again replaces both rather than adding to them.
            'email-verification:' . $account->userId,
            false,
            [Channel::EMAIL],
        );
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
