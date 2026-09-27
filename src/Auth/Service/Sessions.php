<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\AccountRegistrar;
use App\Auth\Domain\JoinDecision;
use App\Auth\Domain\LocalCredentialRepository;
use App\Auth\Domain\RefreshRotation;
use App\Auth\Domain\RefreshTokenRepository;
use App\Auth\Domain\RegisteredAccount;
use App\Auth\Domain\StoredRefreshToken;
use App\Auth\Domain\TokenIssuer;
use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
use App\Notification\Domain\NotificationRepository;
use App\Product\Domain\ProductRepository;
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
     * Ninety days, then sign in again, however busy the session (ADR-062).
     *
     * Rotation grants another {@see REFRESH_LIFETIME} on every refresh, so
     * without a ceiling a session in daily use — or a stolen one kept busy —
     * never has to end. `AUTH_SESSION_MAX_AGE` overrides it.
     */
    public const MAX_SESSION_AGE = 7_776_000;

    /**
     * Thirty seconds in which a token whose replacement has **already been
     * used** is still read as a race rather than a theft (ADR-062).
     *
     * A replacement that has *not* been used is handed out again at any age —
     * that is a lost answer, and derivation makes re-sending it safe. This
     * window covers the one case derivation cannot: a request carrying the
     * old token still in flight after another caller — typically the product
     * beside the platform, which shares the cookie but not the browser's
     * lock — has already rotated the new one. The caller is given the live
     * end of the chain, which is recomputed rather than forked.
     */
    public const RACE_WINDOW = 30;

    /** How far a race is followed along a chain before it is called something else. */
    private const RACE_STEPS = 8;

    /** What {@see exchange()} says when it has no token to give. */
    private const STOLEN = 'stolen';
    private const REFUSED = 'refused';

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
        private readonly ProductRepository $products,
        /**
         * Where this deployment is reachable, for the link in a confirmation
         * email. Empty when nobody configured it, and the link is then
         * relative — which is useless in a mail client and honest about it,
         * where a guessed host would send people to somebody else's site.
         */
        private readonly string $appUrl = '',
        /** What a refresh token is replaced by (ADR-062). */
        private readonly RefreshRotation $rotation = new RefreshRotation(''),
        private readonly int $maxSessionAge = self::MAX_SESSION_AGE,
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

        return [$this->start($account->userId, $account->authSubject, $account->email, $productCode)[0], $account];
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
    public function signIn(string $email, #[SensitiveParameter] string $password, ?string $productCode = null): Session
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

        return $this->start($credential->userId, $credential->authSubject, $credential->email, $productCode)[0];
    }

    /**
     * Exchanges a refresh token for a new pair (ADR-062).
     *
     * **The replacement is derived, so the answer is the same however often
     * it is asked.** A live token is rotated into `HMAC(key, token)`; the
     * same token presented again is handed that same replacement, for as
     * long as the replacement has not been used. Two tabs refreshing at once,
     * answers arriving in the other order, an answer lost to a closed lid or
     * an aborted reload — every one of them ends with the browser holding the
     * token the server holds, because there was only ever one to hold.
     *
     * **Theft is a replacement that has been used, presented from before
     * it.** Only a second holder can do that: the browser that used the
     * replacement stored it, and a cookie jar does not go backwards. The
     * sign-in it came from is revoked — that family, not the account, so a
     * glitch on one device does not sign the person out of all of them.
     * Detection is one rotation later than it was: a thief who presents the
     * stolen token before its owner refreshes is handed the same replacement,
     * and the first of the two to use it makes the other a replayer.
     *
     * @param list<string> $presented every `backprod_refresh` the browser sent
     *
     * @throws UnauthenticatedException when no presented token yields a session
     */
    public function refresh(#[SensitiveParameter] array $presented, ?string $productCode = null): Session
    {
        $known = [];

        foreach ($presented as $rawToken) {
            $found = $this->refreshTokens->find($this->hash($rawToken));

            if ($found !== null) {
                $known[] = [$rawToken, $found];
            }
        }

        /**
         * **A live token wins, whatever else came with it** (2026-09-26).
         *
         * There are two cookies the day an operator sets `AUTH_COOKIE_DOMAIN`
         * ({@see RefreshCookie::presented()}), and the host-only one holds a
         * token legitimately rotated away. So the live ones are tried first,
         * and a theft is acted on only when *nothing* presented yields a
         * session: a thief presents the token they stole and no other.
         */
        usort($known, static fn (array $a, array $b): int => (int) $b[1]->usable() <=> (int) $a[1]->usable());

        $stolen = [];

        foreach ($known as [$rawToken, $token]) {
            $outcome = $this->exchange($rawToken, $token);

            if (is_array($outcome)) {
                if (count($known) > 1) {
                    // A browser still carrying a cookie this deployment can no
                    // longer replace in place; worth knowing it is happening.
                    $this->logger->info('A second refresh cookie was presented and ignored', [
                        'user_id' => $token->userId,
                        'presented' => count($known),
                    ]);
                }

                return $this->resumed($outcome[0], $outcome[1], $productCode);
            }

            if ($outcome === self::STOLEN) {
                $stolen[$token->familyId] = $token->userId;
            }
        }

        foreach ($stolen as $familyId => $userId) {
            $revoked = $this->refreshTokens->revokeFamily($familyId);

            $this->logger->warning('Refresh token reused after its replacement was used; revoked that sign-in', [
                'user_id' => $userId,
                'family_id' => $familyId,
                'revoked' => $revoked,
            ]);
        }

        throw new UnauthenticatedException();
    }

    /**
     * What one presented token is worth: the live token the browser should
     * hold now — its row and its raw value, returned rather than remembered
     * on the object, which serves more than one request — or a refusal and
     * whether it is evidence of theft.
     *
     * No side effect on a refusal — the caller decides what a theft costs,
     * once it knows nothing else presented succeeded.
     *
     * @return array{StoredRefreshToken, string}|string
     */
    private function exchange(#[SensitiveParameter] string $rawToken, StoredRefreshToken $token): array|string
    {
        if ($token->expired || $token->familyAgeSeconds >= $this->maxSessionAge) {
            // Somebody who left a tab open too long, or a sign-in that has
            // reached its age. Neither is anybody's fault.
            return self::REFUSED;
        }

        if ($token->usable()) {
            $successorRaw = $this->rotation->successorOf($rawToken);
            $successorId = $this->refreshTokens->rotate(
                $token->id,
                $this->hash($successorRaw),
                self::REFRESH_LIFETIME,
                $this->maxSessionAge,
            );

            // Somebody may have rotated it between our read and the lock —
            // the repository then answers the replacement they wrote, which
            // is the one derived here, and the paths below hand it over.
            return $successorId === null ? self::REFUSED : $this->handOver($rawToken, $successorId, 0);
        }

        if (!$token->rotated() || $token->replacedBy === null) {
            // Ended on purpose — a sign-out, a password reset, a revoked
            // sign-in — and not by a rotation. A copy presented afterwards
            // outlived the moment it was ended; its sign-in goes (again).
            return self::STOLEN;
        }

        return $this->handOver($rawToken, $token->replacedBy, 0);
    }

    /**
     * Follows a rotation to the token the browser should hold now.
     *
     * - The replacement is live: it is handed out — again, if it already was.
     *   This is the lost answer, and it has no time limit, because nobody but
     *   the holder of the token it replaced can be asking.
     * - The replacement has itself been rotated **within {@see RACE_WINDOW}**:
     *   a request that left before the rotation, from a caller the browser's
     *   lock does not cover. The chain is followed to its live end.
     * - Rotated longer ago: somebody used the replacement and somebody else
     *   is presenting what came before it. Theft.
     *
     * @return array{StoredRefreshToken, string}|string
     */
    private function handOver(#[SensitiveParameter] string $rawToken, string $successorId, int $step): array|string
    {
        $successor = $this->refreshTokens->findById($successorId);

        if ($successor === null) {
            return self::REFUSED;
        }

        $successorRaw = $this->rotation->successorMatching($rawToken, $successor->tokenHash);

        if ($successorRaw === null) {
            // Rotated before replacements were derived, or under a secret
            // this deployment no longer holds. It cannot be handed out again,
            // and nothing about that is suspicious: sign in again.
            return self::REFUSED;
        }

        if ($successor->usable()) {
            return [$successor, $successorRaw];
        }

        if ($successor->expired || $successor->familyAgeSeconds >= $this->maxSessionAge) {
            return self::REFUSED;
        }

        if (!$successor->rotated() || $successor->replacedBy === null) {
            // The sign-in was ended after this token was rotated. Nothing to
            // hand over, and nothing to accuse anybody of.
            return self::REFUSED;
        }

        if ($step < self::RACE_STEPS && ($successor->revokedSecondsAgo ?? PHP_INT_MAX) <= self::RACE_WINDOW) {
            return $this->handOver($successorRaw, $successor->replacedBy, $step + 1);
        }

        return self::STOLEN;
    }

    /** A new access token beside the refresh token the browser now holds. */
    private function resumed(StoredRefreshToken $token, #[SensitiveParameter] string $rawToken, ?string $productCode): Session
    {
        return new Session(
            $this->issuer->issue($token->authSubject, $token->email, $this->audienceFor($productCode)),
            $rawToken,
            max(1, $token->expiresInSeconds),
        );
    }

    /**
     * Ends the sign-in each presented token belongs to.
     *
     * Never throws. A sign-out is somebody saying "I am done", and answering that
     * with an error because the token had already expired would be a worse
     * outcome than the request they asked for. Unknown token, spent token, no
     * token: all of them end with them signed out.
     *
     * The whole sign-in rather than the one token (ADR-062): a replacement the
     * browser never received is still a live token of this sign-in, and a
     * sign-out that left it live would leave a way back in.
     *
     * @param list<string> $presented every `backprod_refresh` the browser sent
     */
    public function signOut(#[SensitiveParameter] array $presented): void
    {
        foreach ($presented as $rawToken) {
            if ($rawToken === '') {
                continue;
            }

            $stored = $this->refreshTokens->find($this->hash($rawToken));

            if ($stored !== null) {
                $this->refreshTokens->revokeFamily($stored->familyId);
            }
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
    private function start(string $userId, string $authSubject, ?string $email, ?string $productCode = null): array
    {
        // 32 bytes from the CSPRNG. The token is the secret; the row holds only
        // its SHA-256, and the database refuses anything that is not one.
        $raw = bin2hex(random_bytes(32));

        // A new sign-in, so a new family: everything rotated from this token
        // belongs to it, and ending it ends them all.
        $issuedId = $this->refreshTokens->start($userId, $this->hash($raw), self::REFRESH_LIFETIME);

        return [
            new Session($this->issuer->issue($authSubject, $email, $this->audienceFor($productCode)), $raw, self::REFRESH_LIFETIME),
            $issuedId,
        ];
    }

    /**
     * The product a token is also addressed to (ADR-051 milestone E): the
     * one the request named in `X-Product`, when it is a product this
     * platform hosts and has not retired. An unknown or retired code names
     * nothing — the token is then the platform's alone, which is what every
     * token was before — rather than a refusal, because signing in is not
     * where a wrong product header should fail.
     */
    private function audienceFor(?string $productCode): ?string
    {
        $named = trim((string) $productCode);

        if ($named === '') {
            return null;
        }

        $product = $this->products->findByCode($named);

        return $product?->active === true ? $product->code : null;
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
