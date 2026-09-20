<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * The top of the model, administrable at last — through the real pipeline and
 * the real database.
 *
 * The gap this closes is not a missing button. `GET /api/v1/products` resolves
 * through membership (`reachableBy` joins `tenant_members`) and a platform role
 * never grants membership (non-negotiable #22), so the platform's own
 * administrator could not list the platform's own products. That is the first
 * thing asserted here, because it is the reason the rest exists.
 *
 * Nothing is doubled but the identity provider. What a product *is* — unique
 * code, an active flag every other read filters on, rows that other tables
 * point at — is a set of claims about PostgreSQL, and an in-memory directory
 * would satisfy them by construction.
 */
#[CoversNothing]
final class ProductAdministrationTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';
    private string $member = '';
    private string $atlas = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );

        $this->admin = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id",
        );
        $this->support = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id",
        );
        $this->member = $this->id(
            "INSERT INTO users (auth_subject, email) VALUES ('sub-mia', 'mia@acme.test') RETURNING id",
        );

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ola-token' => 'sub-ola',
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),
        ]);
    }

    // --- The gap this closes -------------------------------------------------

    public function testAnAdministratorCanListProductsTheyAreNotAMemberOf(): void
    {
        // Ola has a platform role and no membership anywhere — which is the
        // normal state of a platform administrator, and precisely why the
        // membership-scoped route could not answer them.
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT count(*) FROM tenant_members WHERE user_id = :u',
            ['u' => $this->admin],
        ));

        $response = $this->get('/api/v1/staff/products', 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['atlas'], array_column($this->listIn($response, 'products'), 'code'));
    }

    public function testTheMembershipScopedRouteStillAnswersNothingForThem(): void
    {
        // Unchanged, and deliberately so: "which products exist" is commercial
        // information, and the answer belongs behind a permission rather than
        // being widened for everybody because an administrator needed it.
        $response = $this->request('GET', '/api/v1/products', [
            'Authorization' => 'Bearer ola-token',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->listIn($response, 'products'));
    }

    public function testTheCodeIsWhatTheAdministratorCameFor(): void
    {
        $product = $this->listIn($this->get('/api/v1/staff/products', 'ola-token'), 'products')[0];

        // The value a deployment's VITE_DEFAULT_PRODUCT and every `?product=`
        // link has to match. A list without it would answer the wrong question.
        self::assertSame('atlas', $product['code'] ?? null);
        self::assertSame('Atlas', $product['name'] ?? null);
        self::assertTrue($product['active'] ?? null);
    }

    // --- Who may ------------------------------------------------------------

    public function testSupportCannotSeeOrChangeTheProductList(): void
    {
        // SUPPORT_ADMIN reaches every other /staff route. This one is behind
        // its own permission because a product is the unit every tenant,
        // catalogue and invoice hangs from.
        self::assertSame(403, $this->get('/api/v1/staff/products', 'sam-token')->getStatusCode());
        self::assertSame(403, $this->create(['code' => 'orbit', 'name' => 'Orbit'], 'sam-token')->getStatusCode());
    }

    public function testSomebodyWithNoPlatformRoleIsRefusedOutright(): void
    {
        self::assertSame(403, $this->get('/api/v1/staff/products', 'mia-token')->getStatusCode());
    }

    // --- Creating ------------------------------------------------------------

    public function testCreatingAProductMakesItUsableImmediately(): void
    {
        $response = $this->create(['code' => 'orbit', 'name' => 'Orbit'], 'ola-token');

        self::assertSame(201, $response->getStatusCode());

        $created = $this->productIn($response);

        self::assertSame('orbit', $created['code'] ?? null);
        self::assertTrue($created['active'] ?? null);

        // Usable means the storefront can be asked about it — the endpoint a
        // person landing on the front page hits. Empty, because nothing has
        // been advertised, but resolved rather than unknown.
        self::assertSame(200, $this->request('GET', '/api/v1/public/offers?product=orbit')->getStatusCode());
    }

    public function testTheCodeIsLowercasedRatherThanRefused(): void
    {
        $response = $this->create(['code' => 'ORBIT', 'name' => 'Orbit'], 'ola-token');

        self::assertSame(201, $response->getStatusCode());
        // Typed in capitals is a typing habit, not a different product. What
        // is refused is a code that could not survive a URL.
        self::assertSame('orbit', $this->productIn($response)['code'] ?? null);
    }

    public function testACodeThatCouldNotSurviveAUrlIsRefused(): void
    {
        foreach (['my product', 'orbit!', '-orbit', 'orbit/2'] as $bad) {
            $response = $this->create(['code' => $bad, 'name' => 'Orbit'], 'ola-token');

            self::assertSame(400, $response->getStatusCode(), $bad);
            self::assertSame('VALIDATION_FAILED', $this->errorOf($response)['code']);
        }

        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM products'));
    }

    public function testATakenCodeIsAConflictAndCreatesNothing(): void
    {
        $response = $this->create(['code' => 'atlas', 'name' => 'Atlas Again'], 'ola-token');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PRODUCT_CODE_TAKEN', $this->errorOf($response)['code']);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM products'));
    }

    public function testANewProductStartsEmptyRatherThanCopied(): void
    {
        $created = $this->productIn($this->create(['code' => 'orbit', 'name' => 'Orbit'], 'ola-token'));

        self::assertIsString($created['id'] ?? null);

        // No plans, no offers, no tenants. Copying an existing product's
        // catalogue would be a decision nobody asked for, and an expensive one
        // to undo.
        foreach (['plans', 'offers', 'tenant_members'] as $table) {
            self::assertSame(0, $this->connection->fetchOne(
                "SELECT count(*) FROM {$table} WHERE product_id = :p",
                ['p' => $created['id']],
            ), $table);
        }
    }

    // --- Renaming and retiring ------------------------------------------------

    public function testRenamingLeavesTheCodeAlone(): void
    {
        $response = $this->patch($this->atlas, ['name' => 'Atlas Pro'], 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Atlas Pro', $this->productIn($response)['name'] ?? null);
        // The identifier every document, link and configuration key refers to.
        self::assertSame('atlas', $this->productIn($response)['code'] ?? null);
    }

    public function testAnApplicationAddressIsSetShownAndCleared(): void
    {
        // Where a product deployed beside the platform lives (ADR-051 §3).
        $response = $this->patch($this->atlas, ['app_url' => 'https://plan.example.test'], 'ola-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://plan.example.test', $this->productIn($response)['app_url'] ?? null);
        // Listed with it, because the switcher reads the list.
        $listed = $this->decode($this->request('GET', '/api/v1/staff/products', ['Authorization' => 'Bearer ola-token']))['products'] ?? null;
        self::assertIsArray($listed);
        $first = $listed[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('https://plan.example.test', $first['app_url'] ?? null);

        // Null is a value: the product is back inside the shell.
        $cleared = $this->patch($this->atlas, ['app_url' => null], 'ola-token');
        self::assertSame(200, $cleared->getStatusCode());
        $back = $this->productIn($cleared);
        self::assertArrayHasKey('app_url', $back);
        self::assertNull($back['app_url']);

        // https only, and nothing the shell appends itself.
        foreach (['http://plan.example.test', 'https://plan.example.test/?x=1', 'https://plan.example.test/#top', 'not a url'] as $bad) {
            $refused = $this->patch($this->atlas, ['app_url' => $bad], 'ola-token');
            self::assertSame(400, $refused->getStatusCode(), $bad);
            self::assertSame('VALIDATION_FAILED', $this->errorOf($refused)['code'] ?? null, $bad);
        }

        // And the trail says where people will be sent.
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'SET_APP_URL' AND detail->>'app_url' = 'https://plan.example.test'"));
    }

    public function testRenamingDoesNotSwitchAProductOffByOmission(): void
    {
        $this->patch($this->atlas, ['name' => 'Atlas Pro'], 'ola-token');

        // The reason this endpoint is a PATCH. A PUT would have made the form
        // restate `active`, and a checkbox somebody forgot to send would have
        // closed the product.
        self::assertTrue($this->isActive());
    }

    public function testRetiringClosesEveryDoorAndDeletesNothing(): void
    {
        $tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        TestDatabase::assignProduct($this->connection, $tenant, $this->atlas);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:t, :p, :u)',
            ['t' => $tenant, 'p' => $this->atlas, 'u' => $this->member],
        );

        self::assertSame(200, $this->patch($this->atlas, ['active' => false], 'ola-token')->getStatusCode());

        // Every door: the context chain refuses the product outright, so a
        // member of it can no longer act inside it.
        $refused = $this->request('GET', '/api/v1/me/permissions', [
            'Authorization' => 'Bearer mia-token',
            'X-Product' => 'atlas',
        ]);

        self::assertSame(404, $refused->getStatusCode());

        // And nothing is gone. The membership, the tenant and anything hanging
        // from them are exactly where they were — an invoice is a legal
        // document and nothing here deletes one.
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT count(*) FROM tenant_members WHERE product_id = :p',
            ['p' => $this->atlas],
        ));
    }

    public function testAProductCanBeBroughtBack(): void
    {
        $this->patch($this->atlas, ['active' => false], 'ola-token');
        $this->patch($this->atlas, ['active' => true], 'ola-token');

        self::assertTrue($this->isActive());
    }

    public function testARetiredProductIsStillListedInTheConsole(): void
    {
        $this->patch($this->atlas, ['active' => false], 'ola-token');

        $products = $this->listIn($this->get('/api/v1/staff/products', 'ola-token'), 'products');

        // The one view where a retired product is a row rather than a silence.
        // It still carries tenants and invoices, and a list that hid it would
        // suggest those had gone too.
        self::assertCount(1, $products);
        self::assertFalse($products[0]['active'] ?? null);
    }

    public function testThereIsNoWayToDeleteAProduct(): void
    {
        $response = $this->request('DELETE', '/api/v1/staff/products/' . $this->atlas, [
            'Authorization' => 'Bearer ola-token',
        ]);

        // 405, not 403 and not 404: the path is real and the method is not
        // offered at all. A product carries invoices, and §25 keeps those.
        self::assertSame(405, $response->getStatusCode());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM products'));
    }

    public function testAnEmptyChangeIsRefusedRatherThanAbsorbed(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/staff/products/' . $this->atlas,
            ['Authorization' => 'Bearer ola-token'],
            '{}',
        );

        // Answering 200 to a request that changed nothing is how a broken form
        // looks like a working one.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('NOTHING_TO_UPDATE', $this->errorOf($response)['code']);
    }

    public function testChangingAProductThatDoesNotExistIsANotFound(): void
    {
        $response = $this->patch('00000000-0000-0000-0000-000000000000', ['name' => 'Ghost'], 'ola-token');

        self::assertSame(404, $response->getStatusCode());
    }

    // --- The trail ------------------------------------------------------------

    public function testEveryDecisionIsRecordedAgainstWhoMadeIt(): void
    {
        $this->create(['code' => 'orbit', 'name' => 'Orbit'], 'ola-token');
        $this->patch($this->atlas, ['name' => 'Atlas Pro'], 'ola-token');
        $this->patch($this->atlas, ['active' => false], 'ola-token');
        $this->patch($this->atlas, ['active' => true], 'ola-token');

        $actions = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT action FROM staff_access_log
                 WHERE resource_type = 'product' AND staff_user_id = :user
                SQL,
            ['user' => $this->admin],
        );

        // Distinct actions rather than one UPDATE with a payload: "who
        // switched this off?" is the question somebody asks at a bad moment,
        // and a row saying only that it was edited cannot answer it.
        self::assertContains('CREATE', $actions);
        self::assertContains('RENAME', $actions);
        self::assertContains('RETIRE', $actions);
        self::assertContains('REINSTATE', $actions);
    }

    public function testTheTrailNamesThePermissionTheDecisionWasMadeUnder(): void
    {
        $this->patch($this->atlas, ['active' => false], 'ola-token');

        self::assertSame('staff.products.manage', $this->connection->fetchOne(
            "SELECT permission FROM staff_access_log WHERE action = 'RETIRE'",
        ));
    }

    public function testMerelyLookingIsNotRecorded(): void
    {
        $this->get('/api/v1/staff/products', 'ola-token');

        // #21 traces staff crossing into a *tenant's* data. The platform's own
        // product list is not that, and a row per look would bury the
        // decisions among them.
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT count(*) FROM staff_access_log WHERE resource_type = 'product'",
        ));
    }

    // --- Helpers ---------------------------------------------------------------

    private function isActive(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT active FROM products WHERE id = :id',
            ['id' => $this->atlas],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productIn(ResponseInterface $response): array
    {
        $product = $this->decode($response)['product'] ?? null;

        self::assertIsArray($product);

        /** @var array<string, mixed> $product */
        return $product;
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

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body, string $token): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/products',
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $productId, array $body, string $token): ResponseInterface
    {
        return $this->request(
            'PATCH',
            '/api/v1/staff/products/' . $productId,
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
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
