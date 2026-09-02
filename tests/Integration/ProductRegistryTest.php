<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Product\Infrastructure\PostgresProductRegistry;
use App\Tenant\Infrastructure\PostgresTenantMemberRepository;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The product registry against a real database.
 *
 * The milestone's exit criterion is here: a second product, registered the
 * same way as the first, is served by identical code paths. If anything
 * anywhere special-cased a product, these would diverge.
 */
#[CoversNothing]
final class ProductRegistryTest extends DatabaseTestCase
{
    public function testTwoProductsAreServedByTheSameCodePaths(): void
    {
        $atlas = $this->seedProduct('atlas');
        $beacon = $this->seedProduct('beacon');
        $this->seedFeature($atlas, 'projects', 'Projects', true);
        $this->seedFeature($beacon, 'projects', 'Projects', true);

        $user = $this->seedUser('sub-both');
        $this->join($user, $atlas, 'acme');
        $this->join($user, $beacon, 'globex');

        $registry = new PostgresProductRegistry($this->connection);

        self::assertSame(
            ['atlas', 'beacon'],
            array_map(static fn ($p): string => $p->code, $registry->reachableBy($user)),
        );

        // Same shape from both, with nothing product-specific in between.
        self::assertEquals($registry->features($atlas), $registry->features($beacon));
    }

    /**
     * A product exists, but this user has no membership in it — so as far as
     * they are concerned it does not exist at all.
     */
    public function testAProductWithoutMembershipIsInvisible(): void
    {
        $atlas = $this->seedProduct('atlas');
        $this->seedProduct('beacon');

        $user = $this->seedUser('sub-alice');
        $this->join($user, $atlas, 'acme');

        $registry = new PostgresProductRegistry($this->connection);

        self::assertSame(['atlas'], array_map(static fn ($p): string => $p->code, $registry->reachableBy($user)));
        self::assertNull($registry->reachableProduct($user, $this->productIdFor('beacon')));
    }

    public function testAnInactiveProductIsNotListed(): void
    {
        $atlas = $this->seedProduct('atlas');
        $retired = $this->seedProduct('retired', false);

        $user = $this->seedUser('sub-alice');
        $this->join($user, $atlas, 'acme');
        $this->join($user, $retired, 'acme-retired');

        $registry = new PostgresProductRegistry($this->connection);

        self::assertSame(['atlas'], array_map(static fn ($p): string => $p->code, $registry->reachableBy($user)));
        self::assertNull($registry->reachableProduct($user, $retired));
    }

    /**
     * A user in two tenants of one product should see that product once, not
     * once per tenant.
     */
    public function testAProductAppearsOnceRegardlessOfTenantCount(): void
    {
        $atlas = $this->seedProduct('atlas');
        $user = $this->seedUser('sub-alice');

        $this->join($user, $atlas, 'acme');
        $this->join($user, $atlas, 'globex');

        $registry = new PostgresProductRegistry($this->connection);

        self::assertCount(1, $registry->reachableBy($user));
    }

    public function testFeaturesReportWhetherTheyAreEnabled(): void
    {
        $atlas = $this->seedProduct('atlas');
        $this->seedFeature($atlas, 'projects', 'Projects', true);
        $this->seedFeature($atlas, 'advanced_3d', 'Advanced 3D', false);

        $features = (new PostgresProductRegistry($this->connection))->features($atlas);

        self::assertSame(['advanced_3d', 'projects'], array_map(static fn ($f): string => $f->code, $features));
        self::assertFalse($features[0]->enabled);
        self::assertTrue($features[1]->enabled);
    }

    /**
     * Configuration is JSONB, so structure must survive the round trip rather
     * than arriving as a string.
     */
    public function testConfigurationDecodesToStructuredValues(): void
    {
        $atlas = $this->seedProduct('atlas');

        $this->connection->executeStatement(
            "INSERT INTO product_configuration (product_id, key, value) VALUES (:id, 'limits', :value)",
            ['id' => $atlas, 'value' => '{"max_projects": 10, "beta": true}'],
        );

        $configuration = (new PostgresProductRegistry($this->connection))->configuration($atlas);

        self::assertSame(['limits' => ['max_projects' => 10, 'beta' => true]], $configuration);
    }

    private function productIdFor(string $code): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM products WHERE code = :code', ['code' => $code]);
        self::assertIsString($id);

        return $id;
    }

    private function seedProduct(string $code, bool $active = true): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, :active) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code), 'active' => $active ? 'true' : 'false'],
        );

        self::assertIsString($id);

        return $id;
    }

    private function seedFeature(string $productId, string $code, string $name, bool $enabled): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_features (product_id, code, name, enabled)
                VALUES (:product, :code, :name, :enabled)
                SQL,
            ['product' => $productId, 'code' => $code, 'name' => $name, 'enabled' => $enabled ? 'true' : 'false'],
        );
    }

    private function seedUser(string $subject): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO users (auth_subject) VALUES (:subject) RETURNING id',
            ['subject' => $subject],
        );

        self::assertIsString($id);

        return $id;
    }

    private function join(string $userId, string $productId, string $tenantSlug): void
    {
        $tenantId = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO tenants (name, slug) VALUES (:name, :slug)
                ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name
                RETURNING id
                SQL,
            ['name' => ucfirst($tenantSlug), 'slug' => $tenantSlug],
        );

        self::assertIsString($tenantId);

        (new PostgresTenantMemberRepository($this->connection))
            ->addMember($tenantId, $productId, $userId, ['USER']);
    }
}
