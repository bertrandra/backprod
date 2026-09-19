<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Auth\Domain\LocalTokens;
use App\Auth\Domain\TokenIssuer;
use App\Auth\Infrastructure\LocalJwtAuthProvider;
use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Shared\Logging\ErrorLogLogger;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * A forgotten password, and the people a subscription covers (2026-09-19).
 *
 * The two share one mechanism — a single-use link, stored as its SHA-256,
 * that sets a password — and one is how the other invites: an owner who
 * adds somebody by address gives them an account with no usable password
 * and a link to set one. So they are proven together, against real tokens
 * and the real chain.
 */
#[CoversNothing]
final class PasswordAndPeopleTest extends DatabaseApiTestCase
{
    private const SECRET = 'a-test-signing-secret-nobody-deploys';
    private const PASSWORD = 'correct horse battery staple';

    private string $atlas = '';
    private string $acme = '';
    private string $ann = '';
    private string $uma = '';
    private string $version = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);

        $this->ann = $this->person('ann@acme.test');
        $this->uma = $this->person('uma@acme.test');
        $this->member($this->ann, 'TENANT_ADMIN');
        $this->member($this->uma, 'USER');

        // An offer that covers three people, and one that covers its buyer alone.
        $plan = $this->id("INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'TEAM', 'Team', 20) RETURNING id", ['p' => $this->atlas]);
        $offer = $this->id("INSERT INTO offers (product_id, plan_id, code, name) VALUES (:p, :plan, 'team', 'Team') RETURNING id", ['p' => $this->atlas, 'plan' => $plan]);
        // Granted while a draft, then published: what a published version
        // grants is what somebody bought, and the trigger holds it frozen.
        $this->version = $this->id(
            "INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units, currency, valid_from) VALUES (:o, 1, 'DRAFT', 'MONTHLY', 4900, 'EUR', now() - interval '1 day') RETURNING id",
            ['o' => $offer],
        );
        $users = $this->id("INSERT INTO features (product_id, code, name, kind, unit) VALUES (:p, 'users', 'Users', 'QUOTA', 'users') RETURNING id", ['p' => $this->atlas]);
        $this->connection->executeStatement(
            'INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value) VALUES (:v, :f, 3)',
            ['v' => $this->version, 'f' => $users],
        );
        $this->connection->executeStatement("UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :v", ['v' => $this->version]);

        $logger = new ErrorLogLogger();
        $this->override([
            TokenIssuer::class => new LocalJwtTokenIssuer(self::SECRET, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE),
            AuthProvider::class => new LocalJwtAuthProvider(self::SECRET, LocalTokens::DEFAULT_ISSUER, LocalTokens::DEFAULT_AUDIENCE, $logger),
        ]);
    }

    // --- A forgotten password ----------------------------------------------------

    public function testForgettingIsAnsweredTheSameWhetherOrNotTheAddressExists(): void
    {
        $known = $this->request('POST', '/api/v1/auth/password/forgot', [], $this->json(['email' => 'uma@acme.test']));
        $unknown = $this->request('POST', '/api/v1/auth/password/forgot', [], $this->json(['email' => 'nobody@elsewhere.test']));

        self::assertSame(202, $known->getStatusCode());
        self::assertSame(202, $unknown->getStatusCode());
        self::assertSame((string) $known->getBody(), (string) $unknown->getBody());

        // One link, for the one account — hashed, single-use, expiring.
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM password_resets'));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'account.password_reset' AND recipient_user_id = :u", ['u' => $this->uma]));
    }

    public function testTheLinkSetsThePasswordOnceAndSignsEverySessionOut(): void
    {
        // Uma is signed in somewhere.
        $before = $this->as('uma@acme.test');
        self::assertSame(200, $this->request('GET', '/api/v1/me', $before)->getStatusCode());

        $this->request('POST', '/api/v1/auth/password/forgot', [], $this->json(['email' => 'uma@acme.test']));
        $token = $this->linkTokenFor($this->uma);

        $reset = $this->request('POST', '/api/v1/auth/password/reset', [], $this->json(['token' => $token, 'password' => 'a brand new passphrase']));
        self::assertSame(200, $reset->getStatusCode());

        // The old password is gone, the new one works.
        $old = $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => 'uma@acme.test', 'password' => self::PASSWORD]));
        self::assertSame(401, $old->getStatusCode());
        $new = $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => 'uma@acme.test', 'password' => 'a brand new passphrase']));
        self::assertSame(200, $new->getStatusCode());

        // Every refresh token is revoked: whoever held a session is out.
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM auth_refresh_tokens WHERE user_id = :u AND revoked_at IS NULL AND issued_at < (SELECT max(issued_at) FROM auth_refresh_tokens WHERE user_id = :u)', ['u' => $this->uma]));
        // And they were told, on the channel nobody can switch off.
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'account.password_changed' AND recipient_user_id = :u", ['u' => $this->uma]));

        // Once: the same link again is dead, and says no more than an unknown one.
        $again = $this->request('POST', '/api/v1/auth/password/reset', [], $this->json(['token' => $token, 'password' => 'yet another passphrase']));
        $bogus = $this->request('POST', '/api/v1/auth/password/reset', [], $this->json(['token' => str_repeat('0', 64), 'password' => 'yet another passphrase']));
        self::assertSame(400, $again->getStatusCode());
        self::assertSame($this->errorOf($again)['code'] ?? null, $this->errorOf($bogus)['code'] ?? null);
        self::assertSame('RESET_LINK_INVALID', $this->errorOf($again)['code'] ?? null);
    }

    // --- The people a subscription covers -----------------------------------------

    public function testTheOwnerAddsPeopleWithinTheQuotaAndTheyAreEntitledByIt(): void
    {
        // Uma buys a seat on the Team offer: three people, herself included.
        $uma = $this->as('uma@acme.test');
        $seat = $this->request('POST', '/api/v1/subscription', $uma, $this->json(['offer_id' => $this->offerId(), 'seat' => true]));
        self::assertSame(201, $seat->getStatusCode());
        self::assertSame($this->uma, $this->decode($seat)['owner_user_id'] ?? null);

        $people = $this->decode($this->request('GET', '/api/v1/subscription/people?seat=1', $uma));
        self::assertSame(3, $people['quota'] ?? null);
        self::assertTrue($people['owner'] ?? null);
        self::assertSame([], $people['members'] ?? null);

        // An existing member of the organisation, by id.
        $added = $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['seat' => true, 'user_id' => $this->ann]));
        self::assertSame(201, $added->getStatusCode());
        self::assertFalse($this->decode($added)['invited'] ?? null);

        // Ann is now entitled by Uma's seat — the feature the offer grants.
        self::assertContains('users', $this->capabilitiesOf($this->as('ann@acme.test')));

        // A stranger, by address: an account with no usable password, a
        // membership, and the invitation link.
        $invited = $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['seat' => true, 'email' => 'zed@elsewhere.test']));
        self::assertSame(201, $invited->getStatusCode());
        self::assertTrue($this->decode($invited)['invited'] ?? null);
        $zed = $this->userId('zed@elsewhere.test');
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM password_resets WHERE user_id = :u AND purpose = 'INVITATION'", ['u' => $zed]));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM notifications WHERE type = 'account.invitation' AND recipient_user_id = :u", ['u' => $zed]));
        // Nobody knows the password: signing in fails until the link sets one.
        self::assertSame(401, $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => 'zed@elsewhere.test', 'password' => self::PASSWORD]))->getStatusCode());

        // Zed sets a password from the link, signs in, and is entitled too.
        $set = $this->request('POST', '/api/v1/auth/password/reset', [], $this->json(['token' => $this->linkTokenFor($zed), 'password' => 'zed chose this one']));
        self::assertSame(200, $set->getStatusCode());
        $zedIn = $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => 'zed@elsewhere.test', 'password' => 'zed chose this one']));
        self::assertSame(200, $zedIn->getStatusCode());
        $zedHeaders = ['Authorization' => 'Bearer ' . $this->tokenIn($zedIn), 'X-Product' => 'atlas'];
        self::assertContains('users', $this->capabilitiesOf($zedHeaders));

        // Three is three: the owner, Ann, Zed. A fourth is refused.
        $fourth = $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['seat' => true, 'email' => 'yan@elsewhere.test']));
        self::assertSame(409, $fourth->getStatusCode());
        self::assertSame('PEOPLE_QUOTA_REACHED', $this->errorOf($fourth)['code'] ?? null);

        // Removing gives the place back, and takes the entitlement away.
        self::assertSame(204, $this->request('DELETE', '/api/v1/subscription/people/' . $this->ann . '?seat=1', $uma)->getStatusCode());
        self::assertNotContains('users', $this->capabilitiesOf($this->as('ann@acme.test')));
        self::assertSame(201, $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['seat' => true, 'email' => 'yan@elsewhere.test']))->getStatusCode());
    }

    public function testOnlyTheOwnerManagesThePeople(): void
    {
        $uma = $this->as('uma@acme.test');
        self::assertSame(201, $this->request('POST', '/api/v1/subscription', $uma, $this->json(['offer_id' => $this->offerId(), 'seat' => true]))->getStatusCode());
        $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['seat' => true, 'user_id' => $this->ann]));

        // Ann is covered by the seat but does not own it: she holds no seat
        // of her own to manage (404), and the organisation has no
        // subscription either.
        $ann = $this->as('ann@acme.test');
        self::assertSame(404, $this->request('POST', '/api/v1/subscription/people', $ann, $this->json(['seat' => true, 'user_id' => $this->uma]))->getStatusCode());

        // The organisation subscribes, by Ann: she owns that one.
        self::assertSame(201, $this->request('POST', '/api/v1/subscription', $ann, $this->json(['offer_id' => $this->offerId()]))->getStatusCode());
        $company = $this->decode($this->request('GET', '/api/v1/subscription/people', $ann));
        self::assertTrue($company['owner'] ?? null);
        // And Uma, though she may manage her own seat, is not its owner.
        $refused = $this->request('POST', '/api/v1/subscription/people', $uma, $this->json(['user_id' => $this->ann]));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('NOT_THE_OWNER', $this->errorOf($refused)['code'] ?? null);
    }

    // --- Helpers ------------------------------------------------------------

    private function offerId(): string
    {
        return $this->id('SELECT offer_id FROM offer_versions WHERE id = :v', ['v' => $this->version]);
    }

    /** The raw token in the last link sent to somebody, read from the notice's payload. */
    private function linkTokenFor(string $userId): string
    {
        $payload = $this->connection->fetchOne(
            "SELECT payload FROM notifications WHERE recipient_user_id = :u AND type IN ('account.password_reset', 'account.invitation') ORDER BY created_at DESC LIMIT 1",
            ['u' => $userId],
        );
        self::assertIsString($payload);
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        $link = $decoded['link'] ?? null;
        self::assertIsString($link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = $query['reset'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * What this person's own context resolved (/me/entitlements): the
     * question a seat's people are answered by, unlike /entitlements, which
     * is the organisation's.
     *
     * @param array<string, string> $headers
     *
     * @return list<mixed>
     */
    private function capabilitiesOf(array $headers): array
    {
        $capabilities = $this->decode($this->request('GET', '/api/v1/me/entitlements', $headers))['capabilities'] ?? null;
        self::assertIsArray($capabilities);

        return array_values($capabilities);
    }

    /** @return array<string, string> */
    private function as(string $email): array
    {
        $response = $this->request('POST', '/api/v1/auth/token', [], $this->json(['email' => $email, 'password' => self::PASSWORD]));
        self::assertSame(200, $response->getStatusCode());

        return ['Authorization' => 'Bearer ' . $this->tokenIn($response), 'X-Product' => 'atlas'];
    }

    private function tokenIn(ResponseInterface $response): string
    {
        $token = $this->decode($response)['access_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function userId(string $email): string
    {
        return $this->id('SELECT id FROM users WHERE email = :email', ['email' => $email]);
    }

    private function person(string $email): string
    {
        $id = $this->id("INSERT INTO users (auth_subject, email) VALUES ('pending', :email) RETURNING id", ['email' => $email]);
        $this->connection->executeStatement("UPDATE users SET auth_subject = 'local:' || id WHERE id = :id", ['id' => $id]);
        $this->connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            ['id' => $id, 'email' => $email, 'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT)],
        );

        return $id;
    }

    private function member(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $this->acme, 'user' => $userId, 'product' => $this->atlas],
        );
        $this->connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = :role',
            ['tenant' => $this->acme, 'user' => $userId, 'product' => $this->atlas, 'role' => $role],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
