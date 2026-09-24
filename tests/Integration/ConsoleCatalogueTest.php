<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The platform pricing its own product, from an empty database.
 *
 * The test that matters here is the last one, and everything above it exists
 * to make it possible: **a fresh installation can go from a product with
 * nothing in it to an offer a stranger can buy**, without a `psql` prompt.
 * That was impossible before this. `INSERT INTO plans` appeared once in the
 * whole repository, in the demo seeder, so `createOffer` — whose insert is
 * `SELECT … FROM plans WHERE id = :planId` — could never match anything.
 *
 * And ADR-040 had made it worse rather than better: `catalog.manage` became a
 * *delegation* to a tenant, so the platform could price its own catalogue only
 * by lending it away and acting as the borrower.
 *
 * Nothing is doubled but the identity provider. What a catalogue *is* — unique
 * codes per product, a kind that grants are written against, one version on
 * sale across a window — is a set of claims about PostgreSQL.
 */
#[CoversNothing]
final class ConsoleCatalogueTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';
    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();

        // A product with *nothing* in it — no plans, no features, no offers —
        // which is what Console → Products leaves behind after creating one.
        // Two products, so "product-scoped" is tested against something real
        // rather than against a fabricated uuid. Their ids are not kept: every
        // route below addresses a product by *code*, which is the point.
        $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->id("INSERT INTO products (code, name, active) VALUES ('orbit', 'Orbit', true) RETURNING id");

        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $this->support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
                // A customer, for the two tests that ask what a *reader*
                // sees: a catalogue translated by the console is only
                // translated if somebody on the other side reads it that
                // way (2026-09-24).
                'ada-token' => 'sub-ada',
            ]),
        ]);
    }

    // --- What a fresh installation looks like --------------------------------

    public function testAFreshProductHasAnEmptyCatalogueRatherThanAnError(): void
    {
        $response = $this->get('/api/v1/staff/catalogue?product=atlas', 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->listIn($response, 'plans'));
        self::assertSame([], $this->listIn($response, 'features'));
    }

    public function testOnlyPlatformAdminsMayAuthorTheCatalogue(): void
    {
        // Support reaches every other /staff route. Pricing is not support's.
        self::assertSame(403, $this->get('/api/v1/staff/catalogue?product=atlas', 'sam-token')->getStatusCode());
        self::assertSame(403, $this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10], 'sam-token')->getStatusCode());
    }

    // --- Plans ---------------------------------------------------------------

    public function testAPlanCanBeCreatedWhichNothingCouldDoBefore(): void
    {
        $response = $this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]);

        self::assertSame(201, $response->getStatusCode());

        $plan = $this->itemIn($response, 'plan');

        self::assertSame('pro', $plan['code'] ?? null);
        self::assertSame(10, $plan['rank'] ?? null);
    }

    public function testRankIsChosenRatherThanDerivedFromTypingOrder(): void
    {
        $this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 20]);
        $this->createPlan(['code' => 'free', 'name' => 'Free', 'rank' => 0]);

        // Free was typed second and sits first, because rank is a commercial
        // fact and not a fact about when somebody typed it in. Zero is a
        // legitimate rank.
        $plans = $this->listIn($this->get('/api/v1/staff/catalogue?product=atlas', 'ola-token'), 'plans');

        self::assertSame(['free', 'pro'], array_column($plans, 'code'));
    }

    public function testAPlanCodeIsUniqueWithinAProductAndNotAcrossThem(): void
    {
        self::assertSame(201, $this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10])->getStatusCode());

        $again = $this->createPlan(['code' => 'pro', 'name' => 'Pro Again', 'rank' => 20]);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('PLAN_CODE_TAKEN', $this->errorOf($again)['code']);

        // The same code under another product is a different plan, and fine.
        $elsewhere = $this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=orbit',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]),
        );

        self::assertSame(201, $elsewhere->getStatusCode());
    }

    public function testReorderingAPlanIsRecordedAsItsOwnAct(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));

        self::assertSame(200, $this->patch('/api/v1/staff/catalogue/plans/' . $planId, ['rank' => 30])->getStatusCode());

        // Which plan sits above which is what an upgrade is measured by, so
        // moving one is a commercial decision and reads as one in the trail.
        self::assertContains('REORDER', $this->actionsOn('plan'));
    }

    public function testRenamingAPlanDoesNotMoveItByOmission(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));

        $this->patch('/api/v1/staff/catalogue/plans/' . $planId, ['name' => 'Professional']);

        self::assertSame(10, $this->connection->fetchOne(
            'SELECT rank FROM plans WHERE id = :id',
            ['id' => $planId],
        ));
    }

    public function testAPlanOfAnotherProductCannotBeReachedById(): void
    {
        $planId = $this->planId($this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=orbit',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]),
        ));

        // Product-scoped in the WHERE, not merely in the URL.
        self::assertSame(404, $this->patch('/api/v1/staff/catalogue/plans/' . $planId, ['name' => 'Stolen'])->getStatusCode());
    }

    // --- Features -------------------------------------------------------------

    public function testAQuotaCarriesItsUnitAndABooleanMayNot(): void
    {
        $quota = $this->createFeature(['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects']);

        self::assertSame(201, $quota->getStatusCode());
        self::assertSame('projects', $this->itemIn($quota, 'feature')['unit'] ?? null);

        $bad = $this->createFeature(['code' => 'api', 'name' => 'API', 'kind' => 'BOOLEAN', 'unit' => 'calls']);

        // Said with the field that is wrong rather than with a constraint's
        // name, which is what the database would have answered.
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($bad)['code']);
    }

    public function testAnUnknownKindIsRefusedWithTheListOfWhatIsExpected(): void
    {
        $response = $this->createFeature(['code' => 'x', 'name' => 'X', 'kind' => 'COUNTER']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(
            'BOOLEAN',
            json_encode($this->errorOf($response)['details'] ?? []) ?: '',
        );
    }

    public function testAFeatureKindCannotBeChangedAfterwards(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects',
        ]));

        // The rename route takes a name and nothing else. Every grant written
        // against this feature meant QUOTA, and flipping it would reinterpret
        // rows already priced into live subscriptions.
        $this->patch('/api/v1/staff/catalogue/features/' . $featureId, ['name' => 'Project slots', 'kind' => 'BOOLEAN']);

        self::assertSame('QUOTA', $this->connection->fetchOne(
            'SELECT kind FROM features WHERE id = :id',
            ['id' => $featureId],
        ));
        self::assertSame('Project slots', $this->connection->fetchOne(
            'SELECT name FROM features WHERE id = :id',
            ['id' => $featureId],
        ));
    }

    /**
     * A feature says its name in five languages (2026-09-24).
     *
     * The English stays on the row and is the key; the other four are rows
     * of their own, written in the same call so a name and its Spanish
     * cannot disagree for the length of a failure between two requests.
     */
    public function testAFeatureIsNamedInEveryLanguageItSpeaks(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'terrasse', 'name' => 'Terrace engine', 'kind' => 'BOOLEAN',
        ]));

        $response = $this->patch('/api/v1/staff/catalogue/features/' . $featureId, [
            'name' => 'Terrace engine',
            'description' => 'Draws a terrace on a parcel.',
            'translations' => [
                'fr' => ['name' => 'Moteur de terrasse', 'description' => 'Dessine une terrasse sur une parcelle.'],
                'de' => ['name' => 'Terrassenmodul'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());

        // The console is answered with all of them, because it is the only
        // place that can finish a half-translated catalogue.
        $feature = $this->decode($response)['feature'] ?? null;
        self::assertIsArray($feature);
        self::assertSame('Terrace engine', $feature['name'] ?? null);
        self::assertSame(
            ['de' => ['name' => 'Terrassenmodul', 'description' => null], 'fr' => ['name' => 'Moteur de terrasse', 'description' => 'Dessine une terrasse sur une parcelle.']],
            $feature['translations'] ?? null,
        );

        // A customer is answered in their own language, and in English for
        // the one nobody has written.
        self::assertSame('Moteur de terrasse', $this->featureNameAsReadBy('fr', 'terrasse'));
        self::assertSame('Terrassenmodul', $this->featureNameAsReadBy('de', 'terrasse'));
        self::assertSame('Terrace engine', $this->featureNameAsReadBy('it', 'terrasse'));
        // German has a name and no description, so the English answers for
        // the sentence while the German answers for the name.
        self::assertSame('Draws a terrace on a parcel.', $this->descriptionAsReadBy('de', 'terrasse'));
    }

    public function testATranslationIsReplacedAsASetAndEnglishIsNeverOneOfThem(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'exports', 'name' => 'Exports', 'kind' => 'QUOTA', 'unit' => 'exports',
        ]));

        $this->patch('/api/v1/staff/catalogue/features/' . $featureId, [
            'name' => 'Exports',
            'translations' => ['fr' => ['name' => 'Exports FR'], 'es' => ['name' => 'Exportaciones']],
        ]);

        // Sent again without Spanish: the set is what the console says it
        // is, so the one it dropped is gone rather than left behind.
        $this->patch('/api/v1/staff/catalogue/features/' . $featureId, [
            'name' => 'Exports',
            'translations' => ['fr' => ['name' => 'Exports FR']],
        ]);

        self::assertSame(['fr'], $this->connection->fetchFirstColumn(
            'SELECT locale FROM feature_translations WHERE feature_id = :id ORDER BY locale',
            ['id' => $featureId],
        ));

        // English has one home, and this is not it.
        $refused = $this->patch('/api/v1/staff/catalogue/features/' . $featureId, [
            'name' => 'Exports',
            'translations' => ['en' => ['name' => 'Something else']],
        ]);

        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($refused)['code'] ?? null);
    }

    public function testDeletingAFeatureTakesItsTranslationsWithIt(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'doomed', 'name' => 'Doomed', 'kind' => 'BOOLEAN',
        ]));

        $this->patch('/api/v1/staff/catalogue/features/' . $featureId, [
            'name' => 'Doomed',
            'translations' => ['fr' => ['name' => 'Condamne']],
        ]);

        // No route deletes a feature, and there may never be one while
        // grants point at it — but the cascade is what makes that decision
        // reversible later without leaving rows nobody can reach.
        $this->connection->executeStatement('DELETE FROM features WHERE id = :id', ['id' => $featureId]);

        $left = $this->connection->fetchOne(
            'SELECT count(*) FROM feature_translations WHERE feature_id = :id',
            ['id' => $featureId],
        );

        self::assertIsNumeric($left);
        self::assertSame(0, (int) $left);
    }

    /**
     * A member of an organisation that holds Atlas, created on first use.
     *
     * Not in `setUp`: this suite is about the platform pricing its own
     * product, and every other test here would gain a fixture it has no
     * use for.
     */
    private function reader(): string
    {
        if ($this->member !== '') {
            return $this->member;
        }

        $atlas = $this->id("SELECT id FROM products WHERE code = 'atlas'");
        $tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->member = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id");

        TestDatabase::assignProduct($this->connection, $tenant, $atlas);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:tenant, :user, :product)',
            ['tenant' => $tenant, 'user' => $this->member, 'product' => $atlas],
        );
        $this->connection->executeStatement(
            "INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :tenant, :user, :product, id FROM roles WHERE code = 'USER'",
            ['tenant' => $tenant, 'user' => $this->member, 'product' => $atlas],
        );

        return $this->member;
    }

    /** What `GET /api/v1/features` answers somebody whose profile says this language. */
    private function featureNameAsReadBy(string $locale, string $code): ?string
    {
        $name = $this->featureAsReadBy($locale, $code)['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function descriptionAsReadBy(string $locale, string $code): ?string
    {
        $description = $this->featureAsReadBy($locale, $code)['description'] ?? null;

        return is_string($description) ? $description : null;
    }

    /** @return array<string, mixed> */
    private function featureAsReadBy(string $locale, string $code): array
    {
        $this->connection->executeStatement(
            'UPDATE users SET locale = :locale WHERE id = :id',
            ['locale' => $locale, 'id' => $this->reader()],
        );

        $features = $this->decode($this->request('GET', '/api/v1/features', [
            'Authorization' => 'Bearer ada-token',
            'X-Product' => 'atlas',
        ]))['features'] ?? [];

        self::assertIsArray($features);

        foreach ($features as $feature) {
            if (is_array($feature) && ($feature['code'] ?? null) === $code) {
                /** @var array<string, mixed> $feature */
                return $feature;
            }
        }

        return [];
    }

    // --- Offers ----------------------------------------------------------------

    public function testAnOfferIsBornDraftAndIsNotOnSale(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));

        $response = $this->createOffer($planId);

        self::assertSame(201, $response->getStatusCode());

        // Publishing is a separate, deliberate act — which is the point of
        // having one.
        self::assertSame('DRAFT', $this->connection->fetchOne('SELECT status FROM offer_versions'));
        self::assertSame([], $this->listIn(
            $this->request('GET', '/api/v1/public/offers?product=atlas'),
            'offers',
        ));
    }

    public function testPublishingPutsThePriceOnSale(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $offerId = $this->offerId($this->createOffer($planId));

        $response = $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 1]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT status FROM offer_versions'));
    }

    public function testANewPriceIsANewVersionRatherThanAnEdit(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $offerId = $this->offerId($this->createOffer($planId));

        $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 1]);

        $response = $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/versions', [
            'billing_period' => 'MONTHLY',
            'price_minor_units' => 3900,
            'currency' => 'EUR',
            // Opens after the first one closes, so publishing it will not
            // collide with the version already on sale.
            'valid_from' => '2027-01-01T00:00:00+00:00',
        ]);

        self::assertSame(201, $response->getStatusCode());

        // Two versions, and the published one is untouched: ADR-033 freezes it.
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM offer_versions'));
        self::assertSame(2900, $this->connection->fetchOne(
            'SELECT price_minor_units FROM offer_versions WHERE version = 1',
        ));
    }

    public function testTwoPricesCannotBeOnSaleAcrossTheSameWindow(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $offerId = $this->offerId($this->createOffer($planId));

        $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 1]);

        // A second version over the same open window.
        $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/versions', [
            'billing_period' => 'MONTHLY',
            'price_minor_units' => 3900,
            'currency' => 'EUR',
        ]);

        $response = $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 2]);

        // The database refuses, rather than leaving two prices for one offer.
        self::assertSame(409, $response->getStatusCode());
    }

    public function testAPlanFromAnotherProductIsRefused(): void
    {
        $foreign = $this->planId($this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=orbit',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]),
        ));

        $response = $this->createOffer($foreign);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM offers'));
    }

    // --- The trail ---------------------------------------------------------------

    public function testEveryActThatChangesWhatCanBeSoldIsRecorded(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $this->createFeature(['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects']);
        $offerId = $this->offerId($this->createOffer($planId));
        $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 1]);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT resource_type, action, permission FROM staff_access_log
                 WHERE staff_user_id = :user
                SQL,
            ['user' => $this->admin],
        );

        $acts = [];
        $permissions = [];

        foreach ($rows as $row) {
            self::assertIsString($row['resource_type']);
            self::assertIsString($row['action']);
            self::assertIsString($row['permission']);

            $acts[] = $row['resource_type'] . ':' . $row['action'];
            $permissions[$row['permission']] = true;
        }

        self::assertContains('plan:CREATE', $acts);
        self::assertContains('feature:CREATE', $acts);
        self::assertContains('offer:CREATE', $acts);
        // The one an auditor comes for: who put this price on sale.
        self::assertContains('offer:PUBLISH', $acts);
        self::assertSame(['staff.catalog.manage'], array_keys($permissions));
    }

    public function testMerelyReadingTheCatalogueIsNotRecorded(): void
    {
        $this->get('/api/v1/staff/catalogue?product=atlas', 'ola-token');

        // #21 traces staff crossing into a tenant's data. A price list is the
        // platform's own.
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM staff_access_log'));
    }

    // --- What an offer grants ------------------------------------------------

    public function testAnOfferCanBeWrittenWithWhatItGrants(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));

        $projects = $this->featureId($this->createFeature(
            ['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects'],
        ));
        $sso = $this->featureId($this->createFeature(['code' => 'sso', 'name' => 'SSO', 'kind' => 'BOOLEAN']));

        $offer = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => 'pro-monthly',
                'name' => 'Pro, monthly',
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
                'grants' => [
                    ['feature_id' => $projects, 'limit' => 50],
                    ['feature_id' => $sso, 'limit' => null],
                ],
            ]),
        );

        self::assertSame(201, $offer->getStatusCode(), (string) $offer->getBody());

        $grants = $this->grantsOfFirstVersion($offer);

        self::assertSame(['projects', 'sso'], array_column($grants, 'feature'));
        self::assertSame(50, $grants[0]['limit'] ?? null);
        // A switch carries null and is *not* an unlimited quota: the two are
        // different entitlements and the flag is what tells them apart.
        self::assertArrayHasKey('limit', $grants[1]);
        self::assertNull($grants[1]['limit']);
        self::assertFalse($grants[1]['unlimited'] ?? null);
    }

    public function testAQuotaWithNoLimitIsUnlimitedAndNotZero(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $projects = $this->featureId($this->createFeature(
            ['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects'],
        ));

        $grants = $this->grantsOfFirstVersion($this->offerGranting($planId, [
            ['feature_id' => $projects, 'limit' => null],
        ]));

        self::assertTrue($grants[0]['unlimited'] ?? null);
        self::assertArrayHasKey('limit', $grants[0]);
        self::assertNull($grants[0]['limit']);
    }

    public function testTheAuthoringViewNamesEachFeatureByIdSoAVersionCanBeRepeated(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $projects = $this->featureId($this->createFeature(
            ['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects'],
        ));

        $created = $this->offerGranting($planId, [['feature_id' => $projects, 'limit' => 50]]);
        $offerId = $this->offerId($created);

        // The id, not only the code. ADR-033 freezes a published version, so a
        // new price is a new version — and a console that knew only the codes
        // could not name the features of the version it was copying from. It
        // sent no grants at all, which put a price on sale that entitled the
        // buyer to nothing.
        $grants = $this->grantsOfFirstVersion($created);

        self::assertSame($projects, $grants[0]['feature_id'] ?? null);

        self::assertSame(200, $this->postJson(
            '/api/v1/staff/catalogue/offers/' . $offerId . '/publish',
            ['version' => 1],
        )->getStatusCode());

        $repeated = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers/' . $offerId . '/versions?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 3400,
                'currency' => 'EUR',
                'grants' => array_map(
                    static fn (array $grant): array => [
                        'feature_id' => $grant['feature_id'],
                        'limit' => $grant['limit'],
                    ],
                    $grants,
                ),
            ]),
        );

        self::assertSame(201, $repeated->getStatusCode(), (string) $repeated->getBody());

        $versions = $this->itemIn($repeated, 'offer')['versions'] ?? null;

        self::assertIsArray($versions);
        self::assertCount(2, $versions);

        // The new draft costs more and grants exactly what the old one did.
        $second = $versions[1] ?? null;

        self::assertIsArray($second);

        $carried = $second['grants'] ?? null;

        self::assertIsArray($carried);
        self::assertCount(1, $carried);

        $only = $carried[0] ?? null;

        self::assertIsArray($only);
        self::assertSame('projects', $only['feature'] ?? null);
        self::assertSame(50, $only['limit'] ?? null);
    }

    public function testAFeatureOfAnotherProductCannotBeGranted(): void
    {
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));

        $elsewhere = $this->id(
            <<<'SQL'
                INSERT INTO features (product_id, code, name, kind)
                SELECT id, 'seats', 'Seats', 'QUOTA' FROM products WHERE code = 'orbit'
                RETURNING id
                SQL,
        );

        $refused = $this->offerGranting($planId, [['feature_id' => $elsewhere, 'limit' => 5]], 404);

        // Refused rather than ignored. An offer that silently dropped a grant
        // would be sold as granting something it does not.
        self::assertSame('FEATURE_NOT_FOUND', $this->errorOf($refused)['code'] ?? null);
    }

    // --- The whole point -----------------------------------------------------------

    public function testAFreshInstallationCanGoFromNothingToSomethingAStrangerCanBuy(): void
    {
        // A product with nothing in it, as Console → Products creates one. Every
        // step below is a console call, and none of them is SQL.
        $planId = $this->planId($this->createPlan(['code' => 'pro', 'name' => 'Pro', 'rank' => 10]));
        $offerId = $this->offerId($this->createOffer($planId));

        self::assertSame(
            200,
            $this->postJson('/api/v1/staff/catalogue/offers/' . $offerId . '/publish', ['version' => 1])->getStatusCode(),
        );

        // On sale, and still not advertised — being sellable and being shown
        // are two decisions (ADR-041).
        self::assertSame([], $this->listIn(
            $this->request('GET', '/api/v1/public/offers?product=atlas'),
            'offers',
        ));

        self::assertSame(200, $this->request(
            'PUT',
            '/api/v1/staff/storefront/offers/' . $offerId . '?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json(['publicly_listed' => true]),
        )->getStatusCode());

        // A stranger, with no account, now sees a price they can buy.
        $window = $this->listIn($this->request('GET', '/api/v1/public/offers?product=atlas'), 'offers');

        self::assertCount(1, $window);
        self::assertSame('pro-monthly', $window[0]['code'] ?? null);

        $version = $window[0]['version'] ?? null;

        self::assertIsArray($version);

        $price = $version['price'] ?? null;

        self::assertIsArray($price);
        self::assertSame(2900, $price['minor_units'] ?? null);
    }

    // --- Helpers ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function createPlan(array $body, string $token = 'ola-token'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/catalogue/plans?product=atlas',
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createFeature(array $body): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/catalogue/features?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );
    }

    /**
     * An offer granting exactly what it is given, so the grant is the subject.
     *
     * @param list<array<string, mixed>> $grants
     */
    private function offerGranting(string $planId, array $grants, int $expected = 201): ResponseInterface
    {
        $response = $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => 'pro-monthly',
                'name' => 'Pro, monthly',
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
                'grants' => $grants,
            ]),
        );

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grantsOfFirstVersion(ResponseInterface $response): array
    {
        $versions = $this->itemIn($response, 'offer')['versions'] ?? null;

        self::assertIsArray($versions);

        $first = $versions[0] ?? null;

        self::assertIsArray($first);

        $grants = $first['grants'] ?? null;

        self::assertIsArray($grants);

        /** @var list<array<string, mixed>> $grants */
        return $grants;
    }

    private function createOffer(string $planId): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/catalogue/offers?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json([
                'code' => 'pro-monthly',
                'name' => 'Pro, monthly',
                'plan_id' => $planId,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $path, array $body): ResponseInterface
    {
        return $this->request(
            'POST',
            $path . '?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $path, array $body): ResponseInterface
    {
        return $this->request(
            'PATCH',
            $path . '?product=atlas',
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @return list<string>
     */
    private function actionsOn(string $resourceType): array
    {
        $actions = $this->connection->fetchFirstColumn(
            'SELECT action FROM staff_access_log WHERE resource_type = :type',
            ['type' => $resourceType],
        );

        $strings = [];

        foreach ($actions as $action) {
            self::assertIsString($action);
            $strings[] = $action;
        }

        return $strings;
    }

    private function planId(ResponseInterface $response): string
    {
        $id = $this->itemIn($response, 'plan')['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    private function featureId(ResponseInterface $response): string
    {
        $id = $this->itemIn($response, 'feature')['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    private function offerId(ResponseInterface $response): string
    {
        $id = $this->itemIn($response, 'offer')['id'] ?? null;

        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemIn(ResponseInterface $response, string $key): array
    {
        $item = $this->decode($response)[$key] ?? null;

        self::assertIsArray($item, $key . ' missing from ' . (string) $response->getBody());

        /** @var array<string, mixed> $item */
        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listIn(ResponseInterface $response, string $key): array
    {
        $rows = $this->decode($response)[$key] ?? null;

        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = :role
                SQL,
            ['user' => $userId, 'role' => $role],
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
