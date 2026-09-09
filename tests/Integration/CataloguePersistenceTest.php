<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Infrastructure\OfferVersionLoader;
use App\Commerce\Infrastructure\PostgresCatalogueRepository;
use App\Shared\Database\Row;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The catalogue against a real database.
 *
 * Two things can only be shown here: that the constraints protecting
 * commercial data actually refuse bad rows, and that an offer withdrawn from
 * sale is still there afterwards — §12 keeps history rather than deleting it,
 * and a test against a double would only be checking a fixture.
 */
#[CoversNothing]
final class CataloguePersistenceTest extends DatabaseTestCase
{
    private string $atlas = '';
    private string $beacon = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->seedProduct('atlas');
        $this->beacon = $this->seedProduct('beacon');
    }

    public function testPlansAndFeaturesBelongToOneProduct(): void
    {
        $this->seedPlan($this->atlas, 'PRO', 20);
        $this->seedFeature($this->atlas, 'max_projects', 'QUOTA', 'projects');
        $this->seedPlan($this->beacon, 'PRO', 20);

        $catalogue = $this->catalogue();

        // The same code in both products, and neither sees the other's row.
        self::assertCount(1, $catalogue->plansFor($this->atlas));
        self::assertCount(1, $catalogue->plansFor($this->beacon));
        self::assertCount(1, $catalogue->featuresFor($this->atlas));
        self::assertSame([], $catalogue->featuresFor($this->beacon));
    }

    public function testAnOfferLoadsWithItsPlanAndItsGrants(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $projects = $this->seedFeature($this->atlas, 'max_projects', 'QUOTA', 'projects');
        $advanced = $this->seedFeature($this->atlas, 'advanced_3d', 'BOOLEAN', null);

        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');
        $version = $this->seedVersion($offer, 1, OfferVersion::ACTIVE, 2900);
        $this->grant($version, $projects, 50);
        $this->grant($version, $advanced, null);

        $candidates = $this->catalogue()->offersFor($this->atlas);

        self::assertCount(1, $candidates);
        self::assertSame('PRO', $candidates[0]->plan->code);
        self::assertCount(1, $candidates[0]->versions);

        $loaded = $candidates[0]->versions[0];
        self::assertSame(2900, $loaded->priceMinorUnits);
        self::assertSame('EUR', $loaded->currency);

        // Ordered by feature code, and both shapes of grant survive intact.
        self::assertSame(
            ['advanced_3d', 'max_projects'],
            array_map(static fn (OfferGrant $g): string => $g->feature->code, $loaded->grants),
        );
        self::assertNull($loaded->grants[0]->limit);
        self::assertFalse($loaded->grants[0]->isUnlimited(), 'a boolean grant is not an unlimited quota');
        self::assertSame(50, $loaded->grants[1]->limit);
    }

    /**
     * A quota grant with no limit is unlimited; a boolean grant with no limit
     * is simply held. Both are null in the column, and the feature's kind is
     * what tells them apart.
     */
    public function testAnUnlimitedQuotaIsDistinguishableFromABooleanGrant(): void
    {
        $plan = $this->seedPlan($this->atlas, 'ENTERPRISE', 40);
        $projects = $this->seedFeature($this->atlas, 'max_projects', 'QUOTA', 'projects');

        $version = $this->seedVersion($this->seedOffer($this->atlas, $plan, 'enterprise'), 1, OfferVersion::ACTIVE, 0);
        $this->grant($version, $projects, null);

        $candidates = $this->catalogue()->offersFor($this->atlas);

        self::assertTrue($candidates[0]->versions[0]->grants[0]->isUnlimited());
    }

    /**
     * The repository filters by status only; whether a version may be sold
     * now is decided against the clock in one place. A draft is not a
     * candidate at all.
     */
    public function testOnlyActiveVersionsAreCandidates(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');

        $this->seedVersion($offer, 1, OfferVersion::EXPIRED, 1900);
        $this->seedVersion($offer, 2, OfferVersion::ACTIVE, 2900);
        $this->seedVersion($offer, 3, OfferVersion::DRAFT, 3900);

        $candidates = $this->catalogue()->offersFor($this->atlas);

        self::assertCount(1, $candidates[0]->versions);
        self::assertSame(2, $candidates[0]->versions[0]->version);
    }

    /**
     * Newest first, because that is the order the sellable-version choice
     * relies on: an overlap resolves to the most recent terms.
     */
    public function testVersionsArriveNewestFirst(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');

        $this->seedVersion($offer, 1, OfferVersion::ACTIVE, 1900);
        $this->seedVersion($offer, 3, OfferVersion::ACTIVE, 3900);
        $this->seedVersion($offer, 2, OfferVersion::ACTIVE, 2900);

        $candidates = $this->catalogue()->offersFor($this->atlas);

        self::assertSame(
            [3, 2, 1],
            array_map(static fn (OfferVersion $v): int => $v->version, $candidates[0]->versions),
        );
    }

    /**
     * The M5 exit criterion, in the part that can be shown now: expiring a
     * version does not delete it. §12 keeps commercial history, and a
     * subscription pointing at this row must still be able to read what was
     * bought.
     */
    public function testExpiringAVersionLeavesItStoredAndReadable(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $projects = $this->seedFeature($this->atlas, 'max_projects', 'QUOTA', 'projects');
        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');
        $version = $this->seedVersion($offer, 1, OfferVersion::ACTIVE, 2900);
        $this->grant($version, $projects, 50);

        $this->connection->executeStatement(
            "UPDATE offer_versions SET status = 'EXPIRED', valid_until = now() WHERE id = :id",
            ['id' => $version],
        );

        // Gone from the catalogue…
        $candidates = $this->catalogue()->offersFor($this->atlas);
        self::assertSame([], $candidates[0]->versions);

        // …but the terms, and what they granted, are still there.
        $stored = $this->connection->fetchAssociative(
            'SELECT price_minor_units, currency FROM offer_versions WHERE id = :id',
            ['id' => $version],
        );

        self::assertIsArray($stored);

        // Narrowed through the shared helper rather than cast: a row value is
        // mixed, and casting mixed to string is exactly the shortcut that
        // makes a column type change look like a passing test.
        self::assertSame(2900, Row::integer($stored, 'price_minor_units'));
        self::assertSame('EUR', Row::string($stored, 'currency'));
        self::assertSame(50, $this->grantLimitFor($version, $projects));
    }

    public function testAnotherProductsOfferIsNotFound(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');
        $this->seedVersion($offer, 1, OfferVersion::ACTIVE, 2900);

        $catalogue = $this->catalogue();

        self::assertNotNull($catalogue->findOffer($this->atlas, $offer));
        self::assertNull($catalogue->findOffer($this->beacon, $offer));
    }

    public function testAMalformedOfferIdIsNotFoundRatherThanADatabaseError(): void
    {
        self::assertNull(
            $this->catalogue()->findOffer($this->atlas, 'not-a-uuid'),
        );
    }

    /**
     * Constraints that protect money and commercial meaning. Each of these
     * would otherwise be a silent data defect found in an audit.
     *
     * @param array<string, string> $columns
     */
    #[DataProvider('invalidVersions')]
    public function testTheDatabaseRefusesIncoherentTerms(array $columns): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $offer = $this->seedOffer($this->atlas, $plan, 'pro-monthly');

        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO offer_versions (offer_id, version, status, billing_period, price_minor_units,'
                . ' currency, valid_from, valid_until) VALUES (:offer, %s)',
                implode(', ', array_values($columns)),
            ),
            ['offer' => $offer],
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidVersions(): iterable
    {
        $valid = [
            'version' => '1',
            'status' => "'ACTIVE'",
            'billing_period' => "'MONTHLY'",
            'price' => '2900',
            'currency' => "'EUR'",
            'from' => 'now()',
            'until' => 'NULL',
        ];

        // array_merge, not `+`: the union operator keeps the *overriding*
        // array's key order, which would hand the INSERT its values in a
        // different order than its column list — putting the price into the
        // version column and passing for the wrong reason.
        yield 'a negative price' => [array_merge($valid, ['price' => '-1'])];
        yield 'a lowercase currency' => [array_merge($valid, ['currency' => "'eur'"])];
        yield 'a currency that is not three letters' => [array_merge($valid, ['currency' => "'EURO'"])];
        yield 'an unknown status' => [array_merge($valid, ['status' => "'LIVE'"])];
        yield 'an unknown billing period' => [array_merge($valid, ['billing_period' => "'WEEKLY'"])];
        yield 'a version number of zero' => [array_merge($valid, ['version' => '0'])];
        yield 'a window that ends before it starts' => [
            array_merge($valid, ['until' => "now() - interval '1 day'"]),
        ];
    }

    public function testABooleanFeatureCannotCarryAUnit(): void
    {
        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO features (product_id, code, name, kind, unit)
                VALUES (:product, 'advanced_3d', 'Advanced 3D', 'BOOLEAN', 'exports')
                SQL,
            ['product' => $this->atlas],
        );
    }

    /**
     * A feature still granted by an offer cannot be deleted out from under
     * it: ON DELETE RESTRICT, because the alternative is a version whose
     * terms quietly lose a line.
     */
    public function testAFeatureStillGrantedCannotBeDeleted(): void
    {
        $plan = $this->seedPlan($this->atlas, 'PRO', 20);
        $projects = $this->seedFeature($this->atlas, 'max_projects', 'QUOTA', 'projects');
        $version = $this->seedVersion($this->seedOffer($this->atlas, $plan, 'pro'), 1, OfferVersion::ACTIVE, 2900);
        $this->grant($version, $projects, 50);

        $this->expectException(DriverException::class);

        $this->connection->executeStatement('DELETE FROM features WHERE id = :id', ['id' => $projects]);
    }

    public function testCatalogReadIsGrantedToBothRoles(): void
    {
        $roles = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT r.code
                FROM role_permissions rp
                JOIN roles r ON r.id = rp.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE p.code = 'catalog.read'
                ORDER BY r.code
                SQL,
        );

        self::assertSame(['TENANT_ADMIN', 'USER'], $roles);
    }

    // --- Seeding ------------------------------------------------------------

    private function grantLimitFor(string $versionId, string $featureId): ?int
    {
        $limit = $this->connection->fetchOne(
            <<<'SQL'
                SELECT limit_value FROM offer_version_features
                WHERE offer_version_id = :version AND feature_id = :feature
                SQL,
            ['version' => $versionId, 'feature' => $featureId],
        );

        return is_numeric($limit) ? (int) $limit : null;
    }

    private function seedProduct(string $code): string
    {
        return $this->id(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
        );
    }

    private function seedPlan(string $productId, string $code, int $rank): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO plans (product_id, code, name, rank)
                VALUES (:product, :code, :name, :rank) RETURNING id
                SQL,
            ['product' => $productId, 'code' => $code, 'name' => ucfirst(strtolower($code)), 'rank' => $rank],
        );
    }

    private function seedFeature(string $productId, string $code, string $kind, ?string $unit): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO features (product_id, code, name, kind, unit)
                VALUES (:product, :code, :name, :kind, :unit) RETURNING id
                SQL,
            ['product' => $productId, 'code' => $code, 'name' => $code, 'kind' => $kind, 'unit' => $unit],
        );
    }

    private function seedOffer(string $productId, string $planId, string $code): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name)
                VALUES (:product, :plan, :code, :name) RETURNING id
                SQL,
            ['product' => $productId, 'plan' => $planId, 'code' => $code, 'name' => $code],
        );
    }

    private function seedVersion(string $offerId, int $version, string $status, int $price): string
    {
        return $this->id(
            <<<'SQL'
                INSERT INTO offer_versions
                    (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
                VALUES (:offer, :version, :status, 'MONTHLY', :price, 'EUR', now() - interval '1 day')
                RETURNING id
                SQL,
            ['offer' => $offerId, 'version' => $version, 'status' => $status, 'price' => $price],
        );
    }

    private function grant(string $versionId, string $featureId, ?int $limit): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                VALUES (:version, :feature, :limit)
                SQL,
            ['version' => $versionId, 'feature' => $featureId, 'limit' => $limit],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);

        self::assertIsString($id);

        return $id;
    }

    /**
     * The read catalogue over this test's connection.
     *
     * Named rather than constructed at each call site: the repository needs a
     * collaborator to map rows to versions, and nine `new` expressions would
     * be nine places to update the next time it needs another.
     */
    private function catalogue(): PostgresCatalogueRepository
    {
        return new PostgresCatalogueRepository(
            $this->connection,
            new OfferVersionLoader($this->connection),
        );
    }
}
