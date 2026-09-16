<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Who belongs to a tenant, read from the console — through the real
 * pipeline and the real database.
 *
 * The claims: each person once, across the products the tenant holds, with
 * the roles the mirrored membership carries; narrowed to one product by
 * code; refused without a motive and recorded with one; and not a door a
 * tenant administrator can open (#22), nor a product the tenant does not
 * hold answered with somebody else's members.
 */
#[CoversNothing]
final class StaffTenantMembersTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $boreas = '';
    private string $acme = '';
    private string $globex = '';
    private string $ada = '';
    private string $grace = '';
    private string $sam = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");

        $this->ada = $this->id("INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-ada', 'ada@acme.test', 'Ada') RETURNING id");
        $this->grace = $this->id("INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-grace', 'grace@acme.test', 'Grace') RETURNING id");
        $this->sam = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        // Acme holds both products: Ada administers both, Grace is on Atlas only.
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);
        TestDatabase::assignProduct($this->connection, $this->acme, $this->boreas);
        $this->member($this->acme, $this->ada, $this->atlas, 'TENANT_ADMIN');
        $this->member($this->acme, $this->ada, $this->boreas, 'TENANT_ADMIN');
        $this->member($this->acme, $this->grace, $this->atlas, 'USER');
        // Globex holds Atlas, with nobody in it yet.
        TestDatabase::assignProduct($this->connection, $this->globex, $this->atlas);

        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'SUPPORT_ADMIN'",
            ['user' => $this->sam],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['sam-token' => 'sub-sam', 'ada-token' => 'sub-ada']),
        ]);
    }

    public function testEachPersonOnceWithTheirRolesAndProducts(): void
    {
        $response = $this->members($this->acme);

        self::assertSame(200, $response->getStatusCode());
        $members = $this->decode($response)['members'] ?? null;
        self::assertIsArray($members);
        self::assertCount(2, $members);

        [$ada, $grace] = $members;
        self::assertIsArray($ada);
        self::assertIsArray($grace);
        self::assertSame('ada@acme.test', $ada['email'] ?? null);
        self::assertSame(['TENANT_ADMIN'], $ada['roles'] ?? null);
        self::assertSame(['atlas', 'boreas'], $ada['products'] ?? null);
        self::assertSame(['USER'], $grace['roles'] ?? null);
        self::assertSame(['atlas'], $grace['products'] ?? null);
    }

    public function testNarrowedToOneProductByCode(): void
    {
        $members = $this->decode($this->members($this->acme, 'boreas'))['members'] ?? null;
        self::assertIsArray($members);
        self::assertCount(1, $members);
        $only = $members[0] ?? null;
        self::assertIsArray($only);
        self::assertSame(['boreas'], $only['products'] ?? null);

        // A product the tenant does not hold: nobody is on it here — not an
        // error, and never somebody else's members.
        self::assertSame([], $this->decode($this->members($this->globex, 'boreas'))['members'] ?? null);
    }

    public function testTheReadIsRecordedWithItsMotive(): void
    {
        $this->members($this->acme, 'atlas');

        $row = $this->connection->fetchAssociative(
            "SELECT tenant_id, product_id, action, purpose, reason FROM staff_access_log WHERE resource_type = 'members'",
        );

        self::assertIsArray($row);
        self::assertSame($this->acme, $row['tenant_id'] ?? null);
        self::assertSame($this->atlas, $row['product_id'] ?? null);
        self::assertSame('READ', $row['action'] ?? null);
        self::assertSame('SUPPORT_REQUEST', $row['purpose'] ?? null);
    }

    public function testRefusedWithoutAMotiveAndToATenantAdministrator(): void
    {
        $bare = $this->request('GET', '/api/v1/staff/tenants/' . $this->acme . '/members', ['Authorization' => 'Bearer sam-token']);
        self::assertSame(422, $bare->getStatusCode());

        // Ada administers Acme; that is a tenant role, and it opens no staff door.
        self::assertSame(403, $this->members($this->acme, null, 'ada-token')->getStatusCode());
        self::assertSame(404, $this->members('00000000-0000-0000-0000-000000000000')->getStatusCode());
    }

    private function members(string $tenantId, ?string $product = null, string $token = 'sam-token'): ResponseInterface
    {
        $path = '/api/v1/staff/tenants/' . $tenantId . '/members' . ($product === null ? '' : '?product=' . $product);

        return $this->request('GET', $path, [
            'Authorization' => 'Bearer ' . $token,
            'X-Access-Purpose' => 'SUPPORT_REQUEST',
            'X-Access-Reason' => 'ticket HELP-4182',
        ]);
    }

    private function member(string $tenantId, string $userId, string $productId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $tenantId, 'user' => $userId, 'product' => $productId],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                SELECT :tenant, :user, :product, id FROM roles WHERE code = :role
                SQL,
            ['tenant' => $tenantId, 'user' => $userId, 'product' => $productId, 'role' => $role],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $identifier = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($identifier);

        return $identifier;
    }
}
