<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Migrations\Version20261105090000;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * Plan accepts project schema 15 in a deployment that already holds 1 to 14
 * (2026-11-05): the migration adds 15 and keeps everything already accepted,
 * touches no other product, and leaves a Plan with no list as it was.
 */
#[CoversClass(Version20261105090000::class)]
final class PlanSchemaFifteenMigrationTest extends DatabaseTestCase
{
    public function testFifteenIsAddedToPlanAndNothingElseMoves(): void
    {
        $plan = $this->product('plan', '{"supported":[14,1,2,3,4,5,6,7,8,9,10,11,12,13]}');
        $atlas = $this->product('atlas', '{"supported":[1]}');

        $this->migrate();
        // Forward only, and idempotent: a second pass adds nothing.
        $this->migrate();

        self::assertSame(range(1, 15), $this->supported($plan));
        self::assertSame([1], $this->supported($atlas));
    }

    public function testAPlanThatDeclaresNoListIsLeftAsItWas(): void
    {
        $plan = $this->product('plan', '{"other":true}');

        $this->migrate();

        self::assertSame('{"other": true}', $this->connection->fetchOne(
            "SELECT value::text FROM product_configuration WHERE product_id = :p AND key = 'project_schema_versions'",
            ['p' => $plan],
        ));
    }

    private function product(string $code, string $versions): string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => ucfirst($code)],
        );
        self::assertIsString($id);
        $this->connection->executeStatement(
            "INSERT INTO product_configuration (product_id, key, value) VALUES (:p, 'project_schema_versions', CAST(:v AS jsonb))",
            ['p' => $id, 'v' => $versions],
        );

        return $id;
    }

    private function migrate(): void
    {
        $migration = new Version20261105090000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    /** @return list<int> */
    private function supported(string $product): array
    {
        $json = $this->connection->fetchOne(
            "SELECT value -> 'supported' FROM product_configuration WHERE product_id = :p AND key = 'project_schema_versions'",
            ['p' => $product],
        );
        self::assertIsString($json);
        $list = json_decode($json, true);
        self::assertIsArray($list);

        return array_values(array_map(static fn (mixed $v): int => is_int($v) ? $v : -1, $list));
    }
}
