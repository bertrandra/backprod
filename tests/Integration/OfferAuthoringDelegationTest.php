<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * Who may author offers, and who decides that — through the real pipeline,
 * the real database, and the real membership repository.
 *
 * **The membership repository is deliberately not doubled here**, unlike every
 * other endpoint test in this suite. The claim under test is that
 * `catalog.manage` is *not resolved* while the tenant's `may_author_offers` is
 * false, and that resolution is one JOIN condition in one SQL query. An
 * in-memory repository would satisfy the claim by returning whatever the
 * fixture said, proving only that the test and the fake agree — which is
 * exactly the failure mode this rule cannot afford, because the rule is the
 * only thing standing between one customer and every other customer's prices.
 *
 * Two halves, in the order they matter:
 *
 *   - what a tenant administrator can do while the platform has lent them
 *     nothing, and what changes when it has;
 *   - who may lend it, which is PLATFORM_ADMIN and not support.
 *
 * The role is never touched by any of this. Ada stays TENANT_ADMIN throughout
 * — administrator of her organisation, not of the price list.
 */
#[CoversNothing]
final class OfferAuthoringDelegationTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $plan = '';
    private string $author = '';
    private string $admin = '';
    private string $support = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id(
            "INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id",
        );
        $this->plan = $this->id(
            "INSERT INTO plans (product_id, code, name, rank) VALUES (:p, 'pro', 'Pro', 10) RETURNING id",
            ['p' => $this->product],
        );

        $this->author = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ada', 'ada@acme.test') RETURNING id",
        );
        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $this->support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );

        // Ada is the most privileged thing a customer can be. Everything below
        // is about what that does and does not include.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_members (tenant_id, user_id, product_id)
                VALUES (:tenant, :user, :product)
                SQL,
            ['tenant' => $this->tenant, 'user' => $this->author, 'product' => $this->product],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                SELECT :tenant, :user, :product, id FROM roles WHERE code = 'TENANT_ADMIN'
                SQL,
            ['tenant' => $this->tenant, 'user' => $this->author, 'product' => $this->product],
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ada-token' => 'sub-ada',
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
        ]);
    }

    // --- What a tenant administrator holds, and does not ---------------------

    public function testATenantAdministratorCannotAuthorOffersByDefault(): void
    {
        $response = $this->createOffer('pro-monthly');

        // 403, not 404: the route exists and she is a member of the tenant it
        // belongs to. What she does not have is the permission — because the
        // platform has not resolved it for her.
        self::assertSame(403, $response->getStatusCode());
    }

    public function testTheMissingPermissionIsAbsentRatherThanRefusedLater(): void
    {
        // The distinction matters: a permission that is granted and then
        // checked again by each route is a rule somebody can forget to apply
        // on the seventh route. This one is never in the set at all, so a
        // route that has not been written yet is already covered.
        self::assertNotContains('catalog.manage', $this->permissionsOf('ada-token'));
        self::assertContains('catalog.read', $this->permissionsOf('ada-token'));
    }

    public function testTheRoleIsUntouchedWhileTheCatalogueIsWithheld(): void
    {
        // She is still the administrator of her organisation. Members,
        // billing, the skin — none of that is affected by a decision about
        // the price list.
        self::assertContains('TENANT_ADMIN', $this->rolesOf('ada-token'));
        self::assertContains('members.manage', $this->permissionsOf('ada-token'));
    }

    public function testLendingTheCatalogueGrantsThePermissionAndTheRoute(): void
    {
        self::assertSame(200, $this->lend(true, 'ola-token')->getStatusCode());

        self::assertContains('catalog.manage', $this->permissionsOf('ada-token'));
        self::assertSame(201, $this->createOffer('pro-monthly')->getStatusCode());
    }

    public function testTakingItBackClosesTheRouteAgain(): void
    {
        $this->lend(true, 'ola-token');
        self::assertSame(201, $this->createOffer('pro-monthly')->getStatusCode());

        $this->lend(false, 'ola-token');

        // The offers she authored are not deleted — withdrawing the delegation
        // stops her writing, it does not unpublish a price somebody is paying.
        self::assertNotContains('catalog.manage', $this->permissionsOf('ada-token'));
        self::assertSame(403, $this->createOffer('pro-yearly')->getStatusCode());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM offers'));
    }

    // --- Who decides ---------------------------------------------------------

    public function testSupportMaySeeTheAnswerButNotChangeIt(): void
    {
        // Sam reaches the tenant read — that is what SUPPORT_ADMIN is for —
        // and the flag travels with it, so the console can answer "may they
        // edit their prices?" without being able to decide it.
        $read = $this->request('GET', '/api/v1/staff/tenants/' . $this->tenant, [
            'Authorization' => 'Bearer sam-token',
            'X-Access-Purpose' => 'SUPPORT_REQUEST',
            'X-Access-Reason' => 'ticket HELP-4182',
        ]);

        self::assertSame(200, $read->getStatusCode());
        self::assertFalse($this->tenantIn($read)['may_author_offers']);

        self::assertSame(403, $this->lend(true, 'sam-token')->getStatusCode());
        self::assertFalse($this->mayAuthorInDatabase());
    }

    public function testATenantAdministratorCannotLendItToThemselves(): void
    {
        // The console is not hers to reach at all — a tenant role never
        // becomes a platform role (non-negotiable #22) — so this is 403 before
        // any question of permissions is asked.
        self::assertSame(403, $this->lend(true, 'ada-token')->getStatusCode());
        self::assertFalse($this->mayAuthorInDatabase());
    }

    public function testTheDecisionAnswersWithTheTenantAsItNowStands(): void
    {
        $response = $this->lend(true, 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        // Answering with the new state rather than 204 means the console
        // renders what was decided, not what it assumed would be decided.
        self::assertTrue($this->tenantIn($response)['may_author_offers']);
        self::assertTrue($this->mayAuthorInDatabase());
    }

    public function testAskingForTheStateItIsAlreadyInIsNotAnError(): void
    {
        $this->lend(true, 'ola-token');

        // PUT states a desired state. A console whose toggle is clicked twice
        // on a slow connection asked for the same thing twice; it did not ask
        // to undo the first click.
        self::assertSame(200, $this->lend(true, 'ola-token')->getStatusCode());
        self::assertTrue($this->mayAuthorInDatabase());
    }

    public function testAMissingFlagIsRefusedRatherThanDefaultedToFalse(): void
    {
        $response = $this->request(
            'PUT',
            '/api/v1/staff/tenants/' . $this->tenant . '/offer-authoring',
            ['Authorization' => 'Bearer ola-token'],
            // A JSON object with the field missing, not an empty document:
            // the refusal under test is the validator's, not the decoder's.
            '{}',
        );

        // Defaulting would quietly withdraw a delegation because a client had
        // a serialisation bug.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code']);
    }

    public function testDecidingForATenantThatDoesNotExistIsANotFound(): void
    {
        $response = $this->lend(true, 'ola-token', '00000000-0000-0000-0000-000000000000');

        self::assertSame(404, $response->getStatusCode());
    }

    // --- The trail -----------------------------------------------------------

    public function testTheDecisionIsRecordedAgainstWhoMadeIt(): void
    {
        $this->lend(true, 'ola-token');
        $this->lend(false, 'ola-token');

        $actions = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT action FROM staff_access_log
                 WHERE staff_user_id = :user AND tenant_id = :tenant
                 ORDER BY occurred_at, action
                SQL,
            ['user' => $this->admin, 'tenant' => $this->tenant],
        );

        // Two distinct actions rather than one "UPDATE" twice: somebody
        // reading the log later is asking which way it went, and a row that
        // only says the field was touched cannot answer that.
        self::assertContains('DELEGATE', $actions);
        self::assertContains('REVOKE_DELEGATION', $actions);
    }

    public function testTheTrailNamesThePermissionTheDecisionWasMadeUnder(): void
    {
        $this->lend(true, 'ola-token');

        $permission = $this->connection->fetchOne(
            "SELECT permission FROM staff_access_log WHERE action = 'DELEGATE'",
        );

        // "On what grounds?" is the question a log without this can never
        // answer (non-negotiable #21).
        self::assertSame('staff.tenants.manage', $permission);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function permissionsOf(string $token): array
    {
        return $this->stringsIn($this->context($token), 'permissions');
    }

    /**
     * @return list<string>
     */
    private function rolesOf(string $token): array
    {
        return $this->stringsIn($this->context($token), 'roles');
    }

    /**
     * The membership as the pipeline resolved it, which is the thing under
     * test: not what the fixture inserted, but what the query returned.
     *
     * @return array<string, mixed>
     */
    private function context(string $token): array
    {
        $response = $this->request('GET', '/api/v1/me/permissions', [
            'Authorization' => 'Bearer ' . $token,
            'X-Product' => 'atlas',
        ]);

        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private function stringsIn(array $source, string $key): array
    {
        $values = $source[$key] ?? null;

        self::assertIsArray($values);

        $strings = [];

        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantIn(ResponseInterface $response): array
    {
        $tenant = $this->decode($response)['tenant'] ?? null;

        self::assertIsArray($tenant);

        /** @var array<string, mixed> $tenant */
        return $tenant;
    }

    private function lend(bool $mayAuthor, string $token, ?string $tenantId = null): ResponseInterface
    {
        return $this->request(
            'PUT',
            '/api/v1/staff/tenants/' . ($tenantId ?? $this->tenant) . '/offer-authoring',
            ['Authorization' => 'Bearer ' . $token],
            $this->json(['may_author_offers' => $mayAuthor]),
        );
    }

    private function createOffer(string $code): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/offers',
            ['Authorization' => 'Bearer ada-token', 'X-Product' => 'atlas'],
            $this->json([
                'code' => $code,
                'name' => 'Pro',
                'plan_id' => $this->plan,
                'billing_period' => 'MONTHLY',
                'price_minor_units' => 2900,
                'currency' => 'EUR',
            ]),
        );
    }

    private function mayAuthorInDatabase(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT may_author_offers FROM tenants WHERE id = :id',
            ['id' => $this->tenant],
        );
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
