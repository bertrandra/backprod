<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Storage\Domain\StorageProvider;
use App\Storage\Infrastructure\LocalStorageProvider;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The organisation's logo, end to end (2026-10-05).
 *
 * It replaced the per-product white-label skin. What this proves is the
 * shape of that decision: the administrator sets it under `tenant.manage`
 * with **no entitlement at all**, there is one for the organisation whatever
 * product it is seen in, and another organisation never sees it.
 *
 * The storage provider is the real local one writing to a real directory,
 * because "a refused logo leaves nothing behind" is a claim about the store
 * and an in-memory double would make it true by construction.
 */
#[CoversNothing]
final class TenantLogoTest extends DatabaseApiTestCase
{
    /** A real 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $atlas = '';
    private string $plan = '';
    private string $acme = '';
    private string $basic = '';
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/backprod-logo-' . bin2hex(random_bytes(6));

        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->plan = $this->id("INSERT INTO products (code, name, active) VALUES ('plan', 'Plan', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->basic = $this->id("INSERT INTO tenants (name, slug) VALUES ('Basic', 'basic') RETURNING id");

        $ada = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");
        $raj = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-raj', 'raj@acme.test') RETURNING id");
        $bo = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-bo', 'bo@basic.test') RETURNING id");

        $admin = ['tenant.read', 'tenant.manage'];

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'raj-token' => 'sub-raj',
                'bo-token' => 'sub-bo',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->atlas, 'atlas', 'Atlas', true),
                new Product($this->plan, 'plan', 'Plan', true),
            ]),

            StorageProvider::class => new LocalStorageProvider($this->root),

            // No entitlement repository is overridden and none is needed:
            // nobody here has bought anything, which is the point.
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->acme, $ada, $this->atlas, ['TENANT_ADMIN'], $admin),
                new TenantMembership($this->acme, $ada, $this->plan, ['TENANT_ADMIN'], $admin),
                new TenantMembership($this->acme, $raj, $this->atlas, ['USER'], ['tenant.read']),
                new TenantMembership($this->basic, $bo, $this->atlas, ['TENANT_ADMIN'], $admin),
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($this->root);
        }

        parent::tearDown();
    }

    public function testAMemberWhoMayNotConfigureTheOrganisationIsRefused(): void
    {
        $response = $this->upload('raj-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);

        $removal = $this->request('DELETE', '/api/v1/tenants/current/logo', $this->headers('raj-token'));
        self::assertSame(403, $removal->getStatusCode());
    }

    public function testAnAdministratorSetsItWithoutBuyingAnything(): void
    {
        $response = $this->upload();

        self::assertSame(201, $response->getStatusCode());

        $logo = $this->tenantOf($response)['logo_asset_id'];
        self::assertIsString($logo);

        // An asset, so it went through the same sniffing and the same signed
        // links as every other file rather than through a second path.
        self::assertSame('image/png', $this->connection->fetchOne(
            'SELECT content_type FROM assets WHERE id = :id',
            ['id' => $logo],
        ));
    }

    public function testANonImageIsRefusedAndLeavesNothingBehind(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/tenants/current/logo',
            $this->headers() + ['X-Filename' => 'logo.png'],
            'this is not a picture, whatever the filename claims',
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('LOGO_NOT_AN_IMAGE', $this->errorOf($response)['code'] ?? null);

        $assets = $this->connection->fetchOne('SELECT count(*) FROM assets');
        self::assertSame('0', (string) (is_scalar($assets) ? $assets : 'not counted'));
        self::assertSame([], glob($this->root . '/*') ?: []);
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        $response = $this->request('POST', '/api/v1/tenants/current/logo', $this->headers(), '');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('UPLOAD_EMPTY', $this->errorOf($response)['code'] ?? null);
    }

    public function testItIsTheOrganisationsInEveryProductAndNobodyElses(): void
    {
        $logo = $this->tenantOf($this->upload())['logo_asset_id'];
        self::assertIsString($logo);

        // Set in Atlas, read in Plan: one logo for the organisation.
        $inPlan = $this->tenantOf($this->request('GET', '/api/v1/tenants/current', $this->headers('ada-token', 'plan')));
        self::assertSame($logo, $inPlan['logo_asset_id']);

        // And read by a member who may not change it.
        $byRaj = $this->tenantOf($this->request('GET', '/api/v1/tenants/current', $this->headers('raj-token')));
        self::assertSame($logo, $byRaj['logo_asset_id']);

        // Another organisation on the same product has none.
        $basic = $this->tenantOf($this->request('GET', '/api/v1/tenants/current', $this->headers('bo-token')));
        self::assertNull($basic['logo_asset_id']);
    }

    public function testRemovingTheLogoKeepsTheFile(): void
    {
        $logo = $this->tenantOf($this->upload())['logo_asset_id'];
        self::assertIsString($logo);

        $after = $this->request('DELETE', '/api/v1/tenants/current/logo', $this->headers());

        self::assertSame(200, $after->getStatusCode());
        self::assertNull($this->tenantOf($after)['logo_asset_id']);

        // "Stop using this as our logo" is not "destroy this file".
        $still = $this->connection->fetchOne('SELECT count(*) FROM assets WHERE id = :id', ['id' => $logo]);
        self::assertSame('1', (string) (is_scalar($still) ? $still : 'not counted'));
    }

    // --- Helpers ------------------------------------------------------------

    private function upload(string $token = 'ada-token'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/tenants/current/logo',
            $this->headers($token) + ['X-Filename' => 'acme.png'],
            (string) base64_decode(self::PNG, true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantOf(ResponseInterface $response): array
    {
        $tenant = $this->decode($response)['tenant'] ?? null;

        self::assertIsArray($tenant, (string) $response->getBody());

        /** @var array<string, mixed> $tenant */
        return $tenant;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token = 'ada-token', string $product = 'atlas'): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => $product];
    }

    private function id(string $sql): string
    {
        $id = $this->connection->fetchOne($sql);

        self::assertIsString($id);

        return $id;
    }
}
