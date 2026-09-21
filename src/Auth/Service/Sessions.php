<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Domain\JoinDecision;
use App\Auth\Domain\LocalCredentialRepository;
use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\RegisteredAccount;
use App\Auth\Domain\TokenIssuer;
use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\NotificationRepository;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Tenant\Domain\JoinRequests;
use App\Webhook\Domain\ProductEvents;
use App\Webhook\Domain\ProductEventType;
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

    /** Thirty minutes for a reset link: long enough to find the mail, short enough that a forgotten inbox is not a way in. */
    public const RESET_LIFETIME = 1_800;

    /** Seven days for an invitation: the person did not ask for it and may not look for a week. */
    public const INVITATION_LIFETIME = 604_800;

    public const RESET = 'RESET';
    public const INVITATION = 'INVITATION';

    public function __construct(
        private readonly LocalCredentialRepository $credentials,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenIssuer $issuer,
        private readonly LoggerInterface $logger,
        private readonly AccountRegistrar $registrar,
        private readonly NotificationRepository $notifications,
        private readonly JoinRequests $requests,
        private readonly ProductEvents $events,
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
     * A stranger asks to join the organisation at a root: account, a USER
     * membership that is live or waiting, and a session (2026-09-17).
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
        string $tenantSlug,
        ?string $productCode,
        ?string $locale = null,
    ): array {
        if ($this->registrar->emailIsTaken($email)) {
            throw new ConflictException('EMAIL_TAKEN', 'That address already has an account. Sign in, and ask an administrator to add you.');
        }

        $account = $this->registrar->join(
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $displayName,
            $tenantSlug,
            $productCode,
            $locale,
        );

        $this->askForConfirmation($account);

        // The organisation's products hear of the arrival (ADR-051 §5) —
        // with the status, because a membership waiting on an administrator
        // is not yet a person the product will see.
        $this->events->publishForTenant(ProductEventType::MEMBER_ADDED, $account->tenantId, [
            'member' => ['user_id' => $account->userId, 'status' => $account->membershipStatus],
        ]);

        if ($account->membershipStatus === JoinDecision::PENDING) {
            // Every administrator of the organisation, once: a request nobody
            // is told about is a person waiting on a screen nobody opens.
            foreach ($this->requests->administratorsOf($account->tenantId) as $adminId) {
                $this->notifications->raise(
                    $account->tenantId,
                    $account->productId,
                    $adminId,
                    'member.requested',
                    Category::ACCOUNT,
                    ['email' => $account->email, 'display_name' => $displayName],
                    'member-requested:' . $account->tenantId . ':' . $account->userId,
                    false,
                    [Channel::SCREEN, Channel::EMAIL],
                );
            }
        }

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
     * Somebody forgot their password (2026-09-19).
     *
     * **Always the same answer**, whether the address has an account or not:
     * the request is accepted, and if there is an account a link goes to its
     * address. Saying "no such account" here would be the enumeration the
     * sign-in form goes to lengths to prevent. The link is a SECURITY
     * notice — the one category nobody can switch off, because a reset
     * somebody did not ask for is exactly what they must hear about.
     */
    public function forgotPassword(string $email): void
    {
        $credential = $this->credentials->findByEmail($email);

        if ($credential === null) {
            $this->logger->info('Password reset asked for an unknown address', ['email' => $email]);

            return;
        }

        $this->sendPasswordLink($credential->userId, $credential->email, self::RESET);
    }

    /**
     * A new password from the link, and every session gone (2026-09-19).
     *
     * The token is spent first — so a link opened twice sets a password once
     * — then the hash is replaced, then every refresh token for the account
     * is revoked: whoever held a session before the reset, including whoever
     * made the reset necessary, is signed out. The person signs in afresh,
     * as themselves. Unknown, expired and spent are one answer, and the
     * caller says so once.
     *
     * @return bool false when the link is not live
     */
    public function resetPassword(#[SensitiveParameter] string $rawToken, #[SensitiveParameter] string $password): bool
    {
        if ($rawToken === '') {
            return false;
        }

        $userId = $this->registrar->consumePasswordLink($this->hash($rawToken));

        if ($userId === null) {
            return false;
        }

        $email = $this->registrar->emailOf($userId);

        if ($email === null) {
            return false;
        }

        $this->credentials->save($userId, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        $revoked = $this->refreshTokens->revokeAllFor($userId);
        $this->logger->info('Password reset; every session revoked', ['user_id' => $userId, 'revoked' => $revoked]);

        $home = $this->registrar->homeOf($userId);

        if ($home !== null) {
            $this->notifications->raise(
                $home['tenant_id'],
                $home['product_id'],
                $userId,
                'account.password_changed',
                Category::SECURITY,
                ['email' => $email],
                null,
                false,
                [Channel::EMAIL],
            );
        }

        return true;
    }

    /**
     * The link that sets a password — a reset, or an invitation for somebody
     * who never had one (2026-09-19). Sent to the account's address, under
     * the root of the organisation the person belongs to, so that setting
     * the password lands them where they live. A person with no membership
     * is sent to the bare host.
     */
    public function sendPasswordLink(string $userId, string $email, string $purpose): void
    {
        $raw = bin2hex(random_bytes(32));
        $home = $this->registrar->homeOf($userId);

        $this->registrar->issuePasswordLink(
            $userId,
            $this->hash($raw),
            $purpose === self::INVITATION ? self::INVITATION_LIFETIME : self::RESET_LIFETIME,
            $purpose,
        );

        if ($home === null) {
            // Nowhere to be told: the notification model is per organisation
            // and product. Logged rather than lost silently.
            $this->logger->warning('Password link issued for an account with no membership; no notice sent', ['user_id' => $userId]);

            return;
        }

        $root = $home['is_default'] ? '' : '/' . $home['slug'];

        $this->notifications->raise(
            $home['tenant_id'],
            $home['product_id'],
            $userId,
            $purpose === self::INVITATION ? 'account.invitation' : 'account.password_reset',
            Category::SECURITY,
            [
                'link' => rtrim($this->appUrl, '/') . $root . '/sign-in?reset=' . $raw,
                'email' => $email,
                'purpose' => $purpose,
            ],
            'password-link:' . $userId,
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
