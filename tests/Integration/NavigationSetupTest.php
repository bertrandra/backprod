<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The menu setup (2026-09-17): what the shell shows each kind of person,
 * chosen by the platform administrator, platform-wide.
 *
 * Three things are worth holding. The setup is per *audience* and a member
 * is told only their own — an administrator hiding Invoices from users has
 * not hidden it from herself. "Hide what is empty" is answered with a count
 * the platform made, for the entries it can count, and never for one it
 * cannot. And the whole of it is courtesy: a hidden entry's endpoint still
 * answers, because permissions are the control and this is not.
 */
#[CoversNothing]
final class NavigationSetupTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $sam = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");
        $hedy = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-hedy', 'hedy@platform.test') RETURNING id");
        $ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $grace = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-grace', 'grace@acme.test') RETURNING id");

        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'",
            ['user' => $sam],
        );
        $this->connection->executeStatement(
            "INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = 'SUPPORT_ADMIN'",
            ['user' => $hedy],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'sam-token' => 'sub-sam',
                'hedy-token' => 'sub-hedy',
                'ada-token' => 'sub-ada',
                'grace-token' => 'sub-grace',
            ]),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenant, $ada, $this->product, ['TENANT_ADMIN'], ['billing.read']),
                new TenantMembership($this->tenant, $grace, $this->product, ['USER'], ['billing.read']),
            ]),
        ]);
    }

    // --- The setup ------------------------------------------------------------

    public function testUnsetReadsAsEverythingForEveryAudience(): void
    {
        $response = $this->get('/api/v1/staff/navigation', 'sam-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'navigation' => [
                'platform_admin' => ['hidden' => [], 'hide_empty' => false],
                'tenant_admin' => ['hidden' => [], 'hide_empty' => false],
                'user' => ['hidden' => [], 'hide_empty' => false],
            ],
        ], $this->decode($response));
    }

    public function testTheSetupIsReplacedWholeAndRecorded(): void
    {
        $response = $this->put(self::menus(user: ['invoices', 'quotes'], tenantAdmin: [], platformAdmin: ['audit']), 'sam-token');

        self::assertSame(200, $response->getStatusCode());

        $read = $this->decode($this->get('/api/v1/staff/navigation', 'sam-token'));
        self::assertSame([
            'navigation' => [
                'platform_admin' => ['hidden' => ['audit'], 'hide_empty' => false],
                'tenant_admin' => ['hidden' => [], 'hide_empty' => false],
                'user' => ['hidden' => ['invoices', 'quotes'], 'hide_empty' => false],
            ],
        ], $read);

        // Traceable: who decided what every customer's user cannot find.
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM staff_access_log WHERE action = 'CONFIGURE_NAVIGATION' AND resource_type = 'platform_settings'",
        ));
    }

    public function testAnAudienceLeftOutIsRefusedRatherThanReset(): void
    {
        $body = $this->json(['navigation' => ['user' => ['hidden' => ['invoices'], 'hide_empty' => false]]]);
        $response = $this->request('PUT', '/api/v1/staff/navigation', $this->headers('sam-token'), $body);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnEntryIdThatIsNotAWordIsRefused(): void
    {
        $response = $this->put(self::menus(user: ['<script>'], tenantAdmin: [], platformAdmin: []), 'sam-token');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testOnlyThePermissionHolderMayChangeIt(): void
    {
        // Support reads the console but does not decide what it shows.
        self::assertSame(403, $this->get('/api/v1/staff/navigation', 'hedy-token')->getStatusCode());
        self::assertSame(403, $this->put(self::menus([], [], []), 'hedy-token')->getStatusCode());
        // And a customer's administrator is not staff at all.
        self::assertSame(403, $this->get('/api/v1/staff/navigation', 'ada-token')->getStatusCode());
    }

    // --- What each person is told --------------------------------------------

    public function testAMemberIsToldTheirOwnAudiencesMenuOnly(): void
    {
        $this->put(self::menus(user: ['invoices', 'quotes'], tenantAdmin: ['quotes'], platformAdmin: ['audit']), 'sam-token');

        self::assertSame(['hidden' => ['invoices', 'quotes']], $this->decode($this->get('/api/v1/me/navigation', 'grace-token')));
        self::assertSame(['hidden' => ['quotes']], $this->decode($this->get('/api/v1/me/navigation', 'ada-token')));
    }

    public function testStaffAreToldThePlatformMenu(): void
    {
        $this->put(self::menus(user: ['invoices'], tenantAdmin: [], platformAdmin: ['audit', 'erasure']), 'sam-token');

        // Any platform role, as with /staff/me.
        self::assertSame(['hidden' => ['audit', 'erasure']], $this->decode($this->get('/api/v1/staff/me/navigation', 'hedy-token')));
    }

    public function testHidingWhatIsEmptyIsAnsweredByACountForThisTenant(): void
    {
        $this->put(self::menus(user: [], tenantAdmin: [], platformAdmin: [], hideEmpty: ['user']), 'sam-token');

        // Nothing at all yet: every countable list is empty for Grace.
        $empty = $this->decode($this->get('/api/v1/me/navigation', 'grace-token'))['hidden'] ?? null;
        self::assertIsArray($empty);
        self::assertContains('invoices', $empty);
        self::assertContains('projects', $empty);
        // A subscription never taken out and a fiscal history with no fact in
        // it are "nothing to show" too (2026-09-18).
        self::assertContains('subscription', $empty);
        self::assertContains('tax-reports', $empty);
        // Never a settings screen or a profile: those list nothing to count.
        self::assertNotContains('billing-profile', $empty);
        self::assertNotContains('profile', $empty);

        // Ada's audience did not ask for it: same tenant, nothing hidden.
        self::assertSame(['hidden' => []], $this->decode($this->get('/api/v1/me/navigation', 'ada-token')));

        // One project for Acme, and the entry is back — for Acme.
        $this->connection->executeStatement(
            "INSERT INTO projects (tenant_id, product_id, name, created_by, schema_version, document) VALUES (:t, :p, 'Parcel', (SELECT id FROM users WHERE auth_subject = 'sub-grace'), 1, '{}')",
            ['t' => $this->tenant, 'p' => $this->product],
        );
        $after = $this->decode($this->get('/api/v1/me/navigation', 'grace-token'))['hidden'] ?? null;
        self::assertIsArray($after);
        self::assertNotContains('projects', $after);
        self::assertContains('invoices', $after);
    }

    public function testAHiddenEntrysEndpointStillAnswers(): void
    {
        $this->put(self::menus(user: ['invoices'], tenantAdmin: [], platformAdmin: []), 'sam-token');

        // Courtesy, not control (ui-spec "Gating is data").
        $response = $this->request('GET', '/api/v1/billing/invoices', $this->headers('grace-token') + ['X-Product' => 'atlas']);

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param list<string> $user
     * @param list<string> $tenantAdmin
     * @param list<string> $platformAdmin
     * @param list<string> $hideEmpty audiences that hide what is empty
     *
     * @return array<string, mixed>
     */
    private static function menus(array $user, array $tenantAdmin, array $platformAdmin, array $hideEmpty = []): array
    {
        return ['navigation' => [
            'platform_admin' => ['hidden' => $platformAdmin, 'hide_empty' => in_array('platform_admin', $hideEmpty, true)],
            'tenant_admin' => ['hidden' => $tenantAdmin, 'hide_empty' => in_array('tenant_admin', $hideEmpty, true)],
            'user' => ['hidden' => $user, 'hide_empty' => in_array('user', $hideEmpty, true)],
        ]];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body, string $token): ResponseInterface
    {
        return $this->request('PUT', '/api/v1/staff/navigation', $this->headers($token), $this->json($body));
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, $this->headers($token) + ['X-Product' => 'atlas']);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function rowsMatching(string $sql): int
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
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }
}
