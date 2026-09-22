<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A platform staff member's own profile (2026-09-22).
 *
 * `PATCH /me` resolves a membership and refuses staff who hold none, which
 * left the platform administrator with no way to their own name or
 * language. `PATCH /staff/me` needs nothing but being that person, writes
 * through the same rules, and answers what `GET /staff/me` now says.
 */
#[CoversNothing]
final class StaffProfileTest extends DatabaseApiTestCase
{
    private string $admin = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->executeStatement("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true)");
        $this->admin = $this->id("INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-ola', 'ola@platform.test', 'Ola') RETURNING id");
        $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id");
        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'",
            ['user' => $this->admin],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'mia-token' => 'sub-mia']),
        ]);
    }

    public function testStaffWithNoMembershipRenameThemselvesAndChooseTheirLanguage(): void
    {
        $admin = ['Authorization' => 'Bearer ola-token'];

        // The tenant route refuses them: no product, no membership.
        self::assertSame(403, $this->request('PATCH', '/api/v1/me', $admin + ['X-Product' => 'atlas'], $this->json(['display_name' => 'Ola B.']))->getStatusCode());

        // Their own route does not.
        $before = $this->decode($this->request('GET', '/api/v1/staff/me', $admin))['staff'] ?? null;
        self::assertIsArray($before);
        self::assertSame('en', $before['locale'] ?? null);

        $changed = $this->request('PATCH', '/api/v1/staff/me', $admin, $this->json(['display_name' => 'Ola B.', 'locale' => 'fr']));
        self::assertSame(200, $changed->getStatusCode());
        $staff = $this->decode($changed)['staff'] ?? null;
        self::assertIsArray($staff);
        self::assertSame('Ola B.', $staff['display_name'] ?? null);
        self::assertSame('fr', $staff['locale'] ?? null);
        self::assertSame(['PLATFORM_ADMIN'], $staff['roles'] ?? null);

        // Written where every other reader looks — the identity route, and the row.
        $after = $this->decode($this->request('GET', '/api/v1/staff/me', $admin))['staff'] ?? null;
        self::assertIsArray($after);
        self::assertSame('Ola B.', $after['display_name'] ?? null);
        self::assertSame('fr', $after['locale'] ?? null);
        self::assertSame('fr', $this->connection->fetchOne('SELECT locale FROM users WHERE id = :id', ['id' => $this->admin]));

        // Partial: the language alone leaves the name; null clears it.
        $languageOnly = $this->decode($this->request('PATCH', '/api/v1/staff/me', $admin, $this->json(['locale' => 'de'])))['staff'] ?? null;
        self::assertIsArray($languageOnly);
        self::assertSame('Ola B.', $languageOnly['display_name'] ?? null);
        $cleared = $this->decode($this->request('PATCH', '/api/v1/staff/me', $admin, $this->json(['display_name' => null])))['staff'] ?? null;
        self::assertIsArray($cleared);
        self::assertArrayHasKey('display_name', $cleared);
        self::assertNull($cleared['display_name']);
        self::assertSame('de', $cleared['locale'] ?? null);
    }

    public function testALanguageThePlatformDoesNotSpeakIsRefusedAndNobodyElseMayWriteIt(): void
    {
        $admin = ['Authorization' => 'Bearer ola-token'];

        $refused = $this->request('PATCH', '/api/v1/staff/me', $admin, $this->json(['locale' => 'xx']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('LOCALE_UNKNOWN', $this->errorOf($refused)['code'] ?? null);

        // Not staff: the staff route is not theirs, whatever the body.
        self::assertSame(403, $this->request('PATCH', '/api/v1/staff/me', ['Authorization' => 'Bearer mia-token'], $this->json(['display_name' => 'x']))->getStatusCode());
        self::assertSame(401, $this->request('PATCH', '/api/v1/staff/me', [], $this->json(['display_name' => 'x']))->getStatusCode());
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
