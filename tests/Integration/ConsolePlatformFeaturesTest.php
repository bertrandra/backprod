<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The platform's one list of features (2026-09-24, step 3 of
 * `docs/translatable-fields-spec.md`).
 *
 * A feature is not a thing a product owns; it is a word the platform and a
 * product's code have agreed on. `max_projects` existed once per product
 * until this, and the code that reads it — `ProjectWorkspace::QUOTA`,
 * `SubscriptionPeople::USERS_FEATURE` — depended on every one of those rows
 * having been seeded with the same spelling and the same kind. It is one row
 * now, and no route here names a product.
 *
 * A *grant* is still a product's, because it lives on an offer version:
 * {@see ConsoleCatalogueTest} is where that is shown.
 */
#[CoversNothing]
final class ConsolePlatformFeaturesTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");

        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
                // A customer, for the tests that ask what a *reader* sees: a
                // catalogue translated by the console is only translated if
                // somebody on the other side reads it that way.
                'ada-token' => 'sub-ada',
            ]),
        ]);
    }

    // --- Who may keep the list -------------------------------------------------

    public function testOnlyPlatformAdminsMayKeepTheList(): void
    {
        // Support reaches every other /staff route. Deciding what a product
        // may be sold is not support's, and neither is the vocabulary a
        // product's own code gates on.
        self::assertSame(403, $this->list('sam-token')->getStatusCode());
        self::assertSame(403, $this->createFeature(
            ['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects'],
            'sam-token',
        )->getStatusCode());
    }

    public function testTheListIsEmptyRatherThanAnErrorOnAFreshInstallation(): void
    {
        $response = $this->list();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->listIn($response, 'features'));
    }

    // --- Creating ---------------------------------------------------------------

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

    /**
     * A code is taken platform-wide, and that is the whole change.
     *
     * It used to be unique per product, which is how `max_projects` came to
     * exist five times meaning five slightly different things.
     */
    public function testACodeIsTakenOnceForTheWholePlatform(): void
    {
        self::assertSame(201, $this->createFeature(
            ['code' => 'max_projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects'],
        )->getStatusCode());

        $again = $this->createFeature(
            ['code' => 'max_projects', 'name' => 'Projects again', 'kind' => 'QUOTA', 'unit' => 'projects'],
        );

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('FEATURE_CODE_TAKEN', $this->errorOf($again)['code'] ?? null);
    }

    public function testAFeatureKindCannotBeChangedAfterwards(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects',
        ]));

        // The update route takes a name and nothing else of that sort. Every
        // grant written against this feature meant QUOTA, and flipping it
        // would reinterpret rows already priced into live subscriptions.
        $this->patchFeature($featureId, ['name' => 'Project slots', 'kind' => 'BOOLEAN']);

        self::assertSame('QUOTA', $this->connection->fetchOne(
            'SELECT kind FROM features WHERE id = :id',
            ['id' => $featureId],
        ));
        self::assertSame('Project slots', $this->connection->fetchOne(
            'SELECT name FROM features WHERE id = :id',
            ['id' => $featureId],
        ));
    }

    // --- Retiring ---------------------------------------------------------------

    /**
     * Retiring is not deleting, and the list keeps showing it.
     *
     * A feature some offer version grants can never be removed — the foreign
     * key refuses it, and so does the rule that a customer keeps what they
     * bought. So the list gains a flag instead of a delete, and the code
     * stays taken: a console that hid retired rows would refuse a retyped
     * code with nothing on screen to explain the refusal.
     */
    public function testRetiringLeavesTheRowOnTheListAndStopsNewOffersGrantingIt(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'legacy_export', 'name' => 'Legacy export', 'kind' => 'BOOLEAN',
        ]));

        $retired = $this->patchFeature($featureId, ['name' => 'Legacy export', 'active' => false]);

        self::assertSame(200, $retired->getStatusCode());
        self::assertFalse($this->itemIn($retired, 'feature')['active'] ?? null);

        // Still on the platform's list…
        self::assertSame(['legacy_export'], array_column($this->listIn($this->list(), 'features'), 'code'));

        // …and gone from what a product may sell.
        self::assertSame([], $this->sellableFeatures());

        // Reinstating is the same act the other way round.
        self::assertTrue($this->itemIn(
            $this->patchFeature($featureId, ['name' => 'Legacy export', 'active' => true]),
            'feature',
        )['active'] ?? null);
        self::assertSame(['legacy_export'], $this->sellableFeatures());
    }

    public function testRetiringAndRenamingAreDifferentActsInTheTrail(): void
    {
        $featureId = $this->featureId($this->createFeature([
            'code' => 'legacy_export', 'name' => 'Legacy export', 'kind' => 'BOOLEAN',
        ]));

        $this->patchFeature($featureId, ['name' => 'Legacy exports']);
        $this->patchFeature($featureId, ['name' => 'Legacy exports', 'active' => false]);
        $this->patchFeature($featureId, ['name' => 'Legacy exports', 'active' => true]);

        // An auditor reading a column of RENAME rows would never find the day
        // a product lost the ability to sell something.
        self::assertSame(
            ['CREATE', 'RENAME', 'RETIRE', 'REINSTATE'],
            $this->connection->fetchFirstColumn(
                "SELECT action FROM staff_access_log WHERE resource_type = 'feature' ORDER BY occurred_at, id",
            ),
        );
    }

    /**
     * The list is the platform's own, so the trail names neither a tenant nor
     * a product — and answers for its own permission.
     */
    public function testWritingTheListIsRecordedUnderItsOwnPermissionAndNoScope(): void
    {
        $this->createFeature(['code' => 'projects', 'name' => 'Projects', 'kind' => 'QUOTA', 'unit' => 'projects']);

        $row = $this->connection->fetchAssociative(
            "SELECT tenant_id, product_id, permission FROM staff_access_log WHERE resource_type = 'feature'",
        );

        self::assertIsArray($row);
        self::assertNull($row['tenant_id']);
        self::assertNull($row['product_id']);
        self::assertSame('staff.features.manage', $row['permission']);
    }

    public function testMerelyReadingTheListIsNotRecorded(): void
    {
        $this->list();

        // #21 traces staff crossing into a *tenant's* data. This list belongs
        // to nobody's tenant.
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM staff_access_log'));
    }

    // --- Five languages -----------------------------------------------------------

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

        $response = $this->patchFeature($featureId, [
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

        $this->patchFeature($featureId, [
            'name' => 'Exports',
            'translations' => ['fr' => ['name' => 'Exports FR'], 'es' => ['name' => 'Exportaciones']],
        ]);

        // Sent again without Spanish: the set is what the console says it
        // is, so the one it dropped is gone rather than left behind.
        $this->patchFeature($featureId, [
            'name' => 'Exports',
            'translations' => ['fr' => ['name' => 'Exports FR']],
        ]);

        self::assertSame(['fr'], $this->connection->fetchFirstColumn(
            'SELECT locale FROM feature_translations WHERE feature_id = :id ORDER BY locale',
            ['id' => $featureId],
        ));

        // English has one home, and this is not it.
        $refused = $this->patchFeature($featureId, [
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

        $this->patchFeature($featureId, [
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

    public function testAnUnknownFeatureIsNotFound(): void
    {
        $response = $this->patchFeature('0f2a8c1e-0000-4000-8000-000000000000', ['name' => 'Nothing']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('FEATURE_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    // --- Helpers ---------------------------------------------------------------------

    private function list(string $token = 'ola-token'): ResponseInterface
    {
        return $this->request('GET', '/api/v1/staff/features', ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createFeature(array $body, string $token = 'ola-token'): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/features',
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchFeature(string $featureId, array $body): ResponseInterface
    {
        return $this->request(
            'PATCH',
            '/api/v1/staff/features/' . $featureId,
            ['Authorization' => 'Bearer ola-token'],
            $this->json($body),
        );
    }

    /**
     * What `GET /api/v1/features` offers a product's customers — the list
     * with the retired taken out.
     *
     * @return list<string>
     */
    private function sellableFeatures(): array
    {
        // The reader has to exist, or this would answer an empty list for
        // the wrong reason — which is exactly how an assertion about a
        // retired feature would pass while proving nothing.
        $this->reader();

        $features = $this->decode($this->request('GET', '/api/v1/features', [
            'Authorization' => 'Bearer ada-token',
            'X-Product' => 'atlas',
        ]))['features'] ?? [];

        self::assertIsArray($features);

        $codes = [];

        foreach ($features as $feature) {
            if (is_array($feature) && is_string($feature['code'] ?? null)) {
                $codes[] = $feature['code'];
            }
        }

        return $codes;
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

    /**
     * A member of an organisation that holds Atlas, created on first use.
     *
     * Not in `setUp`: this suite is about the platform's own list, and every
     * other test here would gain a fixture it has no use for.
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

    private function featureId(ResponseInterface $response): string
    {
        $id = $this->itemIn($response, 'feature')['id'] ?? null;

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

        $items = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $items[] = $row;
        }

        return $items;
    }
}
