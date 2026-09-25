<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Service\SubscriptionPeople;
use App\Demo\Domain\DemoWorld;
use App\Project\Service\ProjectWorkspace;
use App\Project\Service\SchemaVersionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The demonstration world prices what the platform enforces (2026-09-22).
 *
 * The fixtures are infrastructure and may not read an application service,
 * so the feature codes and the configuration key they write are named in
 * the domain — and this is where they are held to the constants the
 * platform reads. Until this existed the demo granted a quota of `projects`
 * while the workspace asked for `max_projects`, and every subscriber could
 * create none.
 */
#[CoversClass(DemoWorld::class)]
final class DemoWorldTest extends TestCase
{
    public function testTheDemoGrantsTheQuotasThePlatformReads(): void
    {
        self::assertSame(ProjectWorkspace::QUOTA, DemoWorld::PROJECTS_QUOTA);
        self::assertArrayHasKey(ProjectWorkspace::QUOTA, DemoWorld::FEATURES);
        self::assertArrayHasKey(SubscriptionPeople::USERS_FEATURE, DemoWorld::FEATURES);
        self::assertSame(SchemaVersionPolicy::CONFIGURATION_KEY, DemoWorld::SCHEMA_VERSIONS_KEY);
    }

    public function testEverybodyWithAMembershipCanOpenOnTheDefaultProduct(): void
    {
        // A default product is refused unless the person holds it
        // (`PRODUCT_NOT_HELD`), and a membership is mirrored onto every
        // product its tenant holds — so the rule is that every tenant of
        // every member holds this product. Asserted rather than assumed,
        // because the seeder writes the id straight into the row and the
        // database would take a default nobody can reach.
        self::assertArrayHasKey(DemoWorld::PEOPLE_DEFAULT_PRODUCT, DemoWorld::PRODUCTS);

        foreach (DemoWorld::PEOPLE as $key => $person) {
            foreach ($person['tenants'] as $slug) {
                self::assertContains(
                    DemoWorld::PEOPLE_DEFAULT_PRODUCT,
                    DemoWorld::TENANTS[$slug]['holds'],
                    "{$key} would open on a product {$slug} does not hold",
                );
            }
        }
    }

    public function testEveryReferenceInTheWorldNamesSomethingInIt(): void
    {
        foreach (DemoWorld::TENANTS as $slug => $tenant) {
            foreach ($tenant['holds'] as $code) {
                self::assertArrayHasKey($code, DemoWorld::PRODUCTS, "{$slug} holds an unknown product {$code}");
            }

            self::assertArrayHasKey($slug, DemoWorld::TENANT_ADMINS, "{$slug} has no administrator to run it");
            self::assertSame('TENANT_ADMIN', DemoWorld::PEOPLE[DemoWorld::TENANT_ADMINS[$slug]]['role']);
        }

        foreach (DemoWorld::PEOPLE as $key => $person) {
            foreach ($person['tenants'] as $slug) {
                self::assertArrayHasKey($slug, DemoWorld::TENANTS, "{$key} belongs to an unknown organisation {$slug}");
            }
        }

        foreach (DemoWorld::SEATS as $seat) {
            self::assertContains($seat['product'], DemoWorld::TENANTS[$seat['tenant']]['holds'], 'a seat on a product the tenant does not hold');
            self::assertContains($seat['tenant'], DemoWorld::PEOPLE[$seat['holder']]['tenants'], "{$seat['holder']} holds a seat outside their organisation");
        }

        foreach (DemoWorld::SUBSCRIPTION_PEOPLE as $covered) {
            // Only the owner adds, so the holder named here must be one
            // (2026-09-25). Naming anybody else would make the seeder fail
            // with NOT_THE_OWNER, which is the right refusal reached the
            // slow way.
            self::assertNotEmpty(array_filter(
                DemoWorld::SEATS,
                static fn (array $seat): bool => $seat['tenant'] === $covered['tenant']
                    && $seat['product'] === $covered['product']
                    && $seat['holder'] === $covered['holder'],
            ), "{$covered['holder']} holds no seat to put {$covered['user']} on");
            self::assertContains($covered['tenant'], DemoWorld::PEOPLE[$covered['user']]['tenants'], "{$covered['user']} is outside the organisation covering them");
        }

        foreach (DemoWorld::PROJECTS as $draft) {
            self::assertContains($draft['product'], DemoWorld::TENANTS[$draft['tenant']]['holds'], "{$draft['name']} is on a product its tenant does not hold");
            self::assertContains($draft['tenant'], DemoWorld::PEOPLE[$draft['by']]['tenants'], "{$draft['name']} is made by somebody outside its organisation");

            // A project needs a quota, and since 2026-09-25 the quota comes
            // from a seat that covers **its author** — not from a
            // subscription that happens to exist in the same organisation.
            // Whether it really does is the seeder's business, against the
            // database; what is checkable here is that somebody bought
            // something the author is on.
            $covering = array_filter(
                DemoWorld::SEATS,
                static fn (array $seat): bool => $seat['tenant'] === $draft['tenant']
                    && $seat['product'] === $draft['product']
                    && ($seat['holder'] === $draft['by'] || array_filter(
                        DemoWorld::SUBSCRIPTION_PEOPLE,
                        static fn (array $covered): bool => $covered['tenant'] === $seat['tenant']
                            && $covered['product'] === $seat['product']
                            && $covered['holder'] === $seat['holder']
                            && $covered['user'] === $draft['by'],
                    ) !== []),
            );

            self::assertNotEmpty($covering, "{$draft['name']} is made by somebody no seat covers");
        }
    }
}
