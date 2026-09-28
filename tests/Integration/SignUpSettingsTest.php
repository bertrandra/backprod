<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Whether a new account must prove its address (ADR-063): the platform's
 * choice, off until somebody makes it, and behind a permission of its own.
 */
#[CoversNothing]
final class SignUpSettingsTest extends DatabaseApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['sub-ola', 'ola@platform.test', 'PLATFORM_ADMIN'], ['sub-sam', 'sam@platform.test', 'SUPPORT_ADMIN']] as [$subject, $email, $role]) {
            $user = $this->connection->fetchOne(
                'INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id',
                ['s' => $subject, 'e' => $email],
            );
            self::assertIsString($user);
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    /**
     * Nothing decided, nothing demanded — and no row, so that "nobody has
     * decided" and "somebody decided no" stay distinguishable.
     */
    public function testNobodyHasDecidedAndSoNothingIsDemanded(): void
    {
        $shown = $this->decode($this->request('GET', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token']));
        self::assertFalse($shown['confirm_email'] ?? true);

        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM platform_settings WHERE key = 'sign_up'"));
    }

    public function testTheDemandIsSwitchedOnAndOffAndReadBackEachTime(): void
    {
        $on = $this->request('PUT', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['confirm_email' => true]));
        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        self::assertTrue($this->decode($on)['confirm_email'] ?? false);
        self::assertTrue($this->decode($this->request('GET', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token']))['confirm_email'] ?? false);

        $off = $this->request('PUT', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['confirm_email' => false]));
        self::assertFalse($this->decode($off)['confirm_email'] ?? true);
        self::assertFalse($this->decode($this->request('GET', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token']))['confirm_email'] ?? true);
    }

    /**
     * A boolean, and nothing else read as one. A setting that decided who
     * may use the platform on the strength of a truthy string would be one
     * nobody could predict.
     */
    public function testOnlyABooleanDecidesIt(): void
    {
        $this->request('PUT', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['confirm_email' => true]));

        foreach (['yes', 1, null] as $bogus) {
            $refused = $this->request('PUT', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['confirm_email' => $bogus]));
            self::assertSame(400, $refused->getStatusCode(), var_export($bogus, true));
        }

        self::assertTrue($this->decode($this->request('GET', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer ola-token']))['confirm_email'] ?? false);
    }

    /**
     * Its own permission, held by PLATFORM_ADMIN alone, and never by the
     * mere fact of holding the storefront screen's one: what a stranger is
     * shown and what a stranger must prove are two trusts.
     *
     * The second half asserts against the catalogue rather than by lending
     * the permission to somebody: `platform_role_permissions` is reference
     * data the migrations seed and the fixture reset does not touch, so a
     * test that wrote a grant into it would leave it there for every test
     * that follows.
     */
    public function testItAnswersToItsOwnPermissionAndNotTheStorefrontsOne(): void
    {
        self::assertSame(403, $this->request('GET', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v1/staff/sign-up/settings', ['Authorization' => 'Bearer sam-token'], $this->json(['confirm_email' => true]))->getStatusCode());
        self::assertSame(401, $this->request('PUT', '/api/v1/staff/sign-up/settings', [], $this->json(['confirm_email' => true]))->getStatusCode());

        // Two permissions, two rows, and only PLATFORM_ADMIN on the new one.
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT count(*) FROM platform_permissions WHERE code = 'staff.sign_up.manage'",
        ));
        self::assertSame(['PLATFORM_ADMIN'], $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT r.code
                  FROM platform_role_permissions rp
                  JOIN platform_roles r ON r.id = rp.platform_role_id
                  JOIN platform_permissions p ON p.id = rp.platform_permission_id
                 WHERE p.code = 'staff.sign_up.manage'
                 ORDER BY r.code
                SQL,
        ));
    }
}
