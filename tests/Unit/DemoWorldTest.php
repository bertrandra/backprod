<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Commerce\Service\SubscriptionPeople;
use App\Demo\Domain\DemoWorld;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Project\Domain\DocumentPolicy;
use App\Project\Infrastructure\InMemoryDocumentLimit;
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

    /**
     * The demonstration's payments are the stub's, and say so (2026-09-26).
     *
     * A seeded world moves no money, and `StubPaymentProvider` exists so a
     * row it produced can never be mistaken for a row a real provider
     * produced. The name is written in the domain because the fixtures may
     * not read infrastructure, which leaves exactly one way for the two to
     * drift — a demonstration payment claiming a provider that never saw it
     * — and this is where they are held together.
     */
    /**
     * The 3D view is on every Plan offer, because it is what Plan is.
     *
     * The operator's own answer, asked which offers include it: all of them. It
     * was `from: 'pro'` until 2026-10-01 — a decision about tiers that read as
     * a decision about the product, and a free period that could not show the
     * thing it exists to show.
     *
     * Asserted against the declaration rather than a seeded world, because the
     * declaration is what a reader changes: `from` on the ladder, and a named
     * grant on each plan beside it.
     */
    public function testTheThreeDimensionalViewIsOnEveryPlanOffer(): void
    {
        $plan = DemoWorld::PRODUCTS['plan'];

        // The lowest rung of the ladder, so starter, pro and scale all inherit
        // it — that is what `from` means.
        self::assertSame('starter', $plan['capabilities']['plan.3d']['from']);

        // And each plan outside the ladder names it, because nothing is
        // inherited there.
        foreach ($plan['plans'] as $code => $extra) {
            self::assertArrayHasKey(
                'plan.3d',
                $extra['grants'],
                sprintf('the %s plan should include the 3D view', $code),
            );
        }
    }

    public function testEveryDemonstrationPaymentNamesTheProviderThatDidNotTakeIt(): void
    {
        self::assertSame(StubPaymentProvider::NAME, DemoWorld::PAYMENT_PROVIDER);
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

    /**
     * Every document the world names by file is one the platform would store.
     *
     * A project document named by file escapes every check a PHP constant
     * gets for free — the file can go missing, stop being JSON, or grow past
     * what `DocumentPolicy` accepts, and none of it shows until a seed pass
     * fails somewhere that reads as a bug in the seeder. A real product
     * document is also the thing most likely to change without this
     * repository being told: Plan exports a new one and it is dropped in.
     *
     * So the file is checked here rather than discovered there, against the
     * same policy the workspace applies.
     */
    /**
     * No demonstration project invents a document.
     *
     * A project's document belongs to its product, and this platform may not
     * invent one (§4, §16) — not in a form, and not in a seeded world either.
     * The rows here used to carry `['parcelle' => 'BC 42', …]`, which looked
     * like data, was read by nothing, and was in the format of no product: on
     * screen it is indistinguishable from a project that is broken, which is
     * how the operator found it.
     *
     * So a project carries a document its product exported — named by file —
     * or nothing at all, which is exactly what a project created through the
     * Projects screen carries. Whatever opens one opens the other.
     */
    public function testNoProjectInventsADocument(): void
    {
        foreach (DemoWorld::PROJECTS as $draft) {
            // One assertion and not two branches: a project naming a file must
            // not also write a document � that would be two documents for one
            // project � and one naming none must not invent one. Empty is the
            // answer in both cases.
            self::assertSame(
                [],
                $draft['document'] ?? [],
                $draft['name'] . ' invents a document. A project carries what its product exported, or nothing.',
            );
        }
    }

    public function testEveryDocumentNamedByFileIsOneThePlatformWouldStore(): void
    {
        $checked = 0;

        foreach (DemoWorld::PROJECTS as $draft) {
            $file = $draft['document_file'] ?? null;

            if (!is_string($file)) {
                continue;
            }

            ++$checked;

            $path = DemoWorld::DOCUMENTS . '/' . $file;
            self::assertFileExists($path, $file . ' is named by the world and is not there.');

            $raw = (string) file_get_contents($path);
            $fixture = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

            self::assertInstanceOf(\stdClass::class, $fixture);
            self::assertInstanceOf(\stdClass::class, $fixture->document);

            // The version the export claims has to be one the product takes,
            // or the seed pass ends in a 422 naming neither the file nor the
            // product.
            self::assertContains(
                $fixture->schema_version,
                DemoWorld::schemaVersionsFor($draft['product']),
                $file . ' claims a schema version ' . $draft['product'] . ' does not accept.',
            );

            // The policy the workspace applies, applied here: size, depth, and
            // no asset smuggled in as a data: URI or a very long string.
            (new DocumentPolicy(new InMemoryDocumentLimit()))->assertStorable($fixture->document);

            // And it names nothing this world decides. A re-export is how a
            // second copy of the name would come back, and it would come back
            // silently: the seeder reads `PROJECTS`, so the file's copy would
            // simply sit there disagreeing.
            foreach (['name', 'description', 'tenant', 'product', 'by'] as $decided) {
                self::assertObjectNotHasProperty(
                    $decided,
                    $fixture,
                    $file . ' carries `' . $decided . '`, which the demonstration decides and the seeder reads from PROJECTS.',
                );
            }
        }

        // Otherwise this passes for ever by looping over nothing — which is
        // exactly what would happen if the real document were dropped back to
        // a place-holder.
        self::assertGreaterThan(0, $checked, 'No document is named by file any more.');
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

        foreach (DemoWorld::DECLINED_ORDERS as $unpaid) {
            self::assertContains($unpaid['product'], DemoWorld::TENANTS[$unpaid['tenant']]['holds'], 'an order on a product the tenant does not hold');
            self::assertContains($unpaid['tenant'], DemoWorld::PEOPLE[$unpaid['buyer']]['tenants'], "{$unpaid['buyer']} ordered outside their organisation");

            // And it is not one of the seats: `SEATS` is what the world
            // sold, and a seat nobody paid for was not sold. Placing the
            // same person's second order on a product they already hold a
            // live seat on would be refused anyway — `SEAT_ALREADY_ACTIVE`,
            // which is the right refusal reached the slow way.
            self::assertNotContains(
                $unpaid['tenant'] . '/' . $unpaid['product'] . '/' . $unpaid['buyer'],
                array_map(
                    static fn (array $seat): string => $seat['tenant'] . '/' . $seat['product'] . '/' . $seat['holder'],
                    DemoWorld::SEATS,
                ),
                "{$unpaid['buyer']} already holds the seat their card was refused for",
            );
        }

        foreach (DemoWorld::FREEMIUM as $free) {
            self::assertContains($free['product'], DemoWorld::TENANTS[$free['tenant']]['holds'], 'a free period on a product the tenant does not hold');
            self::assertContains($free['tenant'], DemoWorld::PEOPLE[$free['holder']]['tenants'], "{$free['holder']} is trying a product outside their organisation");

            // And it is not one of the seats either. A live seat and a free
            // period are one live seat too many for one person on one product
            // — `SEAT_ALREADY_ACTIVE`, refused by the door and by a partial
            // unique index behind it, which is the right refusal reached the
            // slow way.
            self::assertNotContains(
                $free['tenant'] . '/' . $free['product'] . '/' . $free['holder'],
                array_map(
                    static fn (array $seat): string => $seat['tenant'] . '/' . $seat['product'] . '/' . $seat['holder'],
                    DemoWorld::SEATS,
                ),
                "{$free['holder']} already holds a seat on the product they are trying",
            );

            // The offer it is taken on is the product's own, and the plan it
            // belongs to is free and does not renew — which is what makes it a
            // free period rather than a plan called one.
            $plan = array_filter(
                DemoWorld::PRODUCTS[$free['product']]['plans'],
                static fn (array $candidate): bool => $candidate['offer']['code'] === $free['offer'],
            );

            self::assertCount(1, $plan, "{$free['offer']} is not an offer {$free['product']} sells");
            self::assertSame(0, reset($plan)['price'], "{$free['offer']} is not free");
            self::assertSame('ENDS_AT_TERM', reset($plan)['renewal'] ?? 'AUTO_RENEW', "{$free['offer']} renews, so it is a free tier and not a free period");
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

            // Or by a free period (2026-09-27), which is a subscription like
            // any other and grants a quota like any other — the only thing it
            // does not do is raise a document. It covers its holder and
            // nobody else: `users` is 1, so there is no list to look through.
            $trying = array_filter(
                DemoWorld::FREEMIUM,
                static fn (array $free): bool => $free['tenant'] === $draft['tenant']
                    && $free['product'] === $draft['product']
                    && $free['holder'] === $draft['by'],
            );

            self::assertNotEmpty(
                $covering + $trying,
                "{$draft['name']} is made by somebody no subscription covers",
            );
        }
    }
}
