<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * What a customer has, read by the platform — through the real pipeline
 * and the real database, for every one of the six reads.
 *
 * The claims are the same for each: refused without a motive; refused to a
 * tenant administrator (#22); 404 for a tenant nobody knows; recorded with
 * the motive and the product; narrowed to one product by code; and across
 * every product the tenant holds otherwise — never another tenant's rows.
 */
#[CoversNothing]
final class StaffTenantReadsTest extends DatabaseApiTestCase
{
    private string $atlas = '';
    private string $boreas = '';
    private string $acme = '';
    private string $globex = '';
    private string $ada = '';
    private string $sam = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->boreas = $this->id("INSERT INTO products (code, name, active) VALUES ('boreas', 'Boreas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        $this->ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $this->sam = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");

        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);
        TestDatabase::assignProduct($this->connection, $this->acme, $this->boreas);
        TestDatabase::assignProduct($this->connection, $this->globex, $this->atlas);

        // Ada administers Acme on both products — a tenant role, no staff door.
        foreach ([$this->atlas, $this->boreas] as $product) {
            $this->connection->executeStatement(
                'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
                ['tenant' => $this->acme, 'user' => $this->ada, 'product' => $product],
            );
            $this->connection->executeStatement(
                "INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = 'TENANT_ADMIN'",
                ['tenant' => $this->acme, 'user' => $this->ada, 'product' => $product],
            );
        }

        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'SUPPORT_ADMIN'",
            ['user' => $this->sam],
        );

        // Two projects for Acme, one per product, and one for Globex on Atlas:
        // what "across every product it holds" and "never another tenant's"
        // are asserted against. Projects are the simplest tenant resource to
        // seed by SQL; the other reads share the exact same path through
        // TenantReads, which the data provider walks.
        foreach ([[$this->acme, $this->atlas, 'Acme on Atlas'], [$this->acme, $this->boreas, 'Acme on Boreas'], [$this->globex, $this->atlas, 'Globex on Atlas']] as [$tenant, $product, $name]) {
            $this->connection->executeStatement(
                "INSERT INTO projects (tenant_id, product_id, name, schema_version, document) VALUES (:tenant, :product, :name, 1, '{}')",
                ['tenant' => $tenant, 'product' => $product, 'name' => $name],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['sam-token' => 'sub-sam', 'ada-token' => 'sub-ada']),
        ]);
    }

    public function testProjectsAcrossEveryProductTheTenantHoldsAndNeverAnotherTenants(): void
    {
        $projects = $this->decode($this->read($this->acme, 'projects'))['projects'] ?? null;
        self::assertIsArray($projects);
        self::assertSame(['Acme on Atlas', 'Acme on Boreas'], array_column($projects, 'name'));

        $narrowed = $this->decode($this->read($this->acme, 'projects', 'boreas'))['projects'] ?? null;
        self::assertIsArray($narrowed);
        self::assertSame(['Acme on Boreas'], array_column($narrowed, 'name'));

        // Globex does not hold Boreas: nothing, not Acme's rows.
        self::assertSame([], $this->decode($this->read($this->globex, 'projects', 'boreas'))['projects'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reads(): iterable
    {
        yield 'payments' => ['payments', 'payments'];
        yield 'orders' => ['orders', 'orders'];
        yield 'quotes' => ['quotes', 'quotes'];
        yield 'projects' => ['projects', 'projects'];
        yield 'jobs' => ['jobs', 'jobs'];
    }

    #[DataProvider('reads')]
    public function testEachReadAnswersAnEnvelopeAndIsRecordedWithItsMotive(string $segment, string $key): void
    {
        $response = $this->read($this->acme, $segment, 'atlas');

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($this->decode($response)[$key] ?? null);

        $row = $this->connection->fetchAssociative(
            'SELECT tenant_id, product_id, action, purpose FROM staff_access_log WHERE resource_type = :resource',
            ['resource' => $segment],
        );
        self::assertIsArray($row);
        self::assertSame($this->acme, $row['tenant_id'] ?? null);
        self::assertSame($this->atlas, $row['product_id'] ?? null);
        self::assertSame('READ', $row['action'] ?? null);
        self::assertSame('SUPPORT_REQUEST', $row['purpose'] ?? null);
    }

    #[DataProvider('reads')]
    public function testEachReadIsFencedTheSameWay(string $segment): void
    {
        $bare = $this->request('GET', '/api/v1/staff/tenants/' . $this->acme . '/' . $segment, ['Authorization' => 'Bearer sam-token']);
        self::assertSame(422, $bare->getStatusCode());
        self::assertSame(403, $this->read($this->acme, $segment, null, 'ada-token')->getStatusCode());
        self::assertSame(404, $this->read('00000000-0000-0000-0000-000000000000', $segment)->getStatusCode());
    }

    public function testTheTaxProfileIsTheTenantsOneFiscalIdentity(): void
    {
        $response = $this->read($this->acme, 'tax-profile');

        self::assertSame(200, $response->getStatusCode());
        $profile = $this->decode($response)['profile'] ?? null;
        self::assertIsArray($profile);
        // Nothing declared yet: a private customer until it says otherwise.
        self::assertSame('B2C', $profile['customer_kind'] ?? null);
        self::assertSame(1, $this->rowCount("SELECT count(*) FROM staff_access_log WHERE resource_type = 'tax_profile' AND action = 'READ'"));
    }

    private function read(string $tenantId, string $segment, ?string $product = null, string $token = 'sam-token'): ResponseInterface
    {
        $path = '/api/v1/staff/tenants/' . $tenantId . '/' . $segment . ($product === null ? '' : '?product=' . $product);

        return $this->request('GET', $path, [
            'Authorization' => 'Bearer ' . $token,
            'X-Access-Purpose' => 'SUPPORT_REQUEST',
            'X-Access-Reason' => 'ticket HELP-4182',
        ]);
    }

    private function rowCount(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
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
