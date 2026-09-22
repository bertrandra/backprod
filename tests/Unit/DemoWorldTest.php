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

    public function testEveryReferenceInTheWorldNamesSomethingInIt(): void
    {
        foreach (DemoWorld::TENANTS as $slug => $tenant) {
            foreach ($tenant['holds'] as $code) {
                self::assertArrayHasKey($code, DemoWorld::PRODUCTS, "{$slug} holds an unknown product {$code}");
            }

            self::assertArrayHasKey($slug, DemoWorld::TENANT_ADMINS, "{$slug} has no administrator to buy for it");
            self::assertSame('TENANT_ADMIN', DemoWorld::PEOPLE[DemoWorld::TENANT_ADMINS[$slug]]['role']);
        }

        foreach (DemoWorld::PEOPLE as $key => $person) {
            foreach ($person['tenants'] as $slug) {
                self::assertArrayHasKey($slug, DemoWorld::TENANTS, "{$key} belongs to an unknown organisation {$slug}");
            }
        }

        foreach (DemoWorld::SUBSCRIPTIONS as $live) {
            self::assertContains($live['product'], DemoWorld::TENANTS[$live['tenant']]['holds'], 'a subscription to a product the tenant does not hold');
        }

        foreach (DemoWorld::PROJECTS as $draft) {
            self::assertContains($draft['product'], DemoWorld::TENANTS[$draft['tenant']]['holds'], "{$draft['name']} is on a product its tenant does not hold");
            self::assertContains($draft['tenant'], DemoWorld::PEOPLE[$draft['by']]['tenants'], "{$draft['name']} is made by somebody outside its organisation");
            // A project needs a quota, and the quota comes from a subscription.
            self::assertNotEmpty(array_filter(
                DemoWorld::SUBSCRIPTIONS,
                static fn (array $live): bool => $live['tenant'] === $draft['tenant'] && $live['product'] === $draft['product'],
            ), "{$draft['name']} has no subscription to count against");
        }
    }
}
