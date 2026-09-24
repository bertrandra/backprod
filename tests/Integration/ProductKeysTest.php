<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * A product beside the platform as a caller of its own (ADR-051 §4).
 *
 * Three claims, each against the real database: a key is issued once and
 * proves itself on every call; the product is derived from the key and a
 * tenant that does not hold the product is absent to it; and every crossing
 * — refusals included — is in the product access log. Then the one thing a
 * key writes: usage, refused against the quota before the work is done, and
 * counted once however often it is reported.
 */
#[CoversNothing]
final class ProductKeysTest extends DatabaseApiTestCase
{
    private string $admin = '';
    private string $support = '';
    private string $plan = '';
    private string $atlas = '';
    private string $acme = '';
    private string $globex = '';
    private string $ada = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = $this->id("INSERT INTO products (code, name, active) VALUES ('plan', 'Plan', true) RETURNING id");
        $this->atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $this->acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->globex = $this->id("INSERT INTO tenants (name, slug) VALUES ('Globex', 'globex') RETURNING id");
        $this->admin = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-ola', 'ola@platform.test') RETURNING id");
        $this->support = $this->id("INSERT INTO users (auth_subject, email) VALUES ('sub-sam', 'sam@platform.test') RETURNING id");
        $this->ada = $this->id("INSERT INTO users (auth_subject, email, display_name) VALUES ('sub-ada', 'ada@acme.test', 'Ada') RETURNING id");

        $this->appoint($this->admin, 'PLATFORM_ADMIN');
        $this->appoint($this->support, 'SUPPORT_ADMIN');

        // Acme holds Plan and Atlas; Globex holds Atlas only.
        TestDatabase::assignProduct($this->connection, $this->acme, $this->plan);
        TestDatabase::assignProduct($this->connection, $this->acme, $this->atlas);
        TestDatabase::assignProduct($this->connection, $this->globex, $this->atlas);
        $this->connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, user_id, product_id) VALUES (:t, :u, :p)',
            ['t' => $this->acme, 'u' => $this->ada, 'p' => $this->plan],
        );
        $this->connection->executeStatement(
            "INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id) SELECT :t, :u, :p, id FROM roles WHERE code = 'USER'",
            ['t' => $this->acme, 'u' => $this->ada, 'p' => $this->plan],
        );

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam', 'ada-token' => 'sub-ada']),
        ]);
    }

    public function testAKeyIsIssuedOnceInTheClearAndListedWithoutIt(): void
    {
        $response = $this->issue(['label' => 'plan production', 'scopes' => ['product.entitlements.read', 'product.members.read']]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        $bearer = $body['bearer'] ?? null;
        $credential = $body['credential'] ?? null;
        self::assertIsString($bearer);
        self::assertIsArray($credential);
        self::assertMatchesRegularExpression('/^bpk_[0-9a-f]{12}_[A-Za-z0-9_-]{43}$/', $bearer);
        self::assertSame('plan production', $credential['label'] ?? null);
        self::assertSame(['product.entitlements.read', 'product.members.read'], $credential['scopes'] ?? null);
        self::assertArrayHasKey('revoked_at', $credential);
        self::assertNull($credential['revoked_at']);
        self::assertIsString($credential['expires_at'] ?? null);

        // Listed, but never with the secret again.
        $listed = $this->decode($this->request('GET', '/api/v1/staff/products/' . $this->plan . '/credentials', $this->staff()))['credentials'] ?? null;
        self::assertIsArray($listed);
        self::assertCount(1, $listed);
        $first = $listed[0] ?? null;
        self::assertIsArray($first);
        self::assertArrayNotHasKey('bearer', $first);
        self::assertArrayNotHasKey('secret_hash', $first);
        $keyId = $first['key_id'] ?? null;
        self::assertIsString($keyId);
        self::assertStringContainsString($keyId, $bearer);

        // And the trail says who issued it.
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'ISSUE_PRODUCT_KEY'"));
    }

    public function testIssuingNeedsTheProductsManagePermissionAndKnownScopes(): void
    {
        self::assertSame(403, $this->issue(['label' => 'x', 'scopes' => ['product.members.read']], 'sam-token')->getStatusCode());
        self::assertSame(400, $this->issue(['label' => 'x', 'scopes' => ['product.everything']])->getStatusCode());
        self::assertSame(400, $this->issue(['label' => 'x', 'scopes' => []])->getStatusCode());
        self::assertSame(404, $this->issue(['label' => 'x', 'scopes' => ['product.members.read']], 'ola-token', '00000000-0000-4000-8000-000000000000')->getStatusCode());
    }

    public function testTheKeyReadsWhatTheTenantHoldsOnItsOwnProductAndNothingElse(): void
    {
        $bearer = $this->bearer(['product.entitlements.read', 'product.members.read']);

        $entitlements = $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', $bearer);
        self::assertSame(200, $entitlements->getStatusCode());
        $body = $this->decode($entitlements);
        self::assertSame($this->acme, $body['tenant_id'] ?? null);
        self::assertIsArray($body['entitlements'] ?? null);
        self::assertIsArray($body['usage'] ?? null);

        $members = $this->decode($this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/members', $bearer));
        $list = $members['members'] ?? null;
        self::assertIsArray($list);
        self::assertCount(1, $list);
        $ada = $list[0] ?? null;
        self::assertIsArray($ada);
        self::assertSame($this->ada, $ada['user_id'] ?? null);
        self::assertSame('ada@acme.test', $ada['email'] ?? null);
        self::assertSame(['USER'], $ada['roles'] ?? null);
        self::assertArrayNotHasKey('password_hash', $ada);

        // Globex does not hold Plan: absent, not forbidden — the same
        // non-answer a person's routes give, so nothing leaks by key either.
        self::assertSame(404, $this->asProduct('GET', '/api/v1/product/tenants/' . $this->globex . '/entitlements', $bearer)->getStatusCode());
        self::assertSame(404, $this->asProduct('GET', '/api/v1/product/tenants/not-a-uuid/entitlements', $bearer)->getStatusCode());

        // No X-Product header is read: the product came from the key.
        $spoofed = $this->request('GET', '/api/v1/product/tenants/' . $this->globex . '/entitlements', [
            'Authorization' => 'Bearer ' . $bearer,
            'X-Product' => 'atlas',
        ]);
        self::assertSame(404, $spoofed->getStatusCode());
    }

    public function testEveryCrossingIsInTheProductAccessLogRefusalsIncluded(): void
    {
        $bearer = $this->bearer(['product.entitlements.read']);

        $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', $bearer);
        $this->asProduct('GET', '/api/v1/product/tenants/' . $this->globex . '/entitlements', $bearer);
        // A scope this key does not hold.
        self::assertSame(403, $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/members', $bearer)->getStatusCode());

        $rows = $this->connection->fetchAllAssociative(
            'SELECT tenant_id, asked_for, method, path, status FROM product_access_log WHERE product_id = :p ORDER BY occurred_at',
            ['p' => $this->plan],
        );
        self::assertCount(3, $rows);
        self::assertSame([200, 404, 403], array_map(static fn (array $r): mixed => $r['status'] ?? null, $rows));
        self::assertSame($this->acme, $rows[0]['tenant_id'] ?? null);
        // The refused tenant was not Plan's to name: only what was asked is kept.
        self::assertArrayHasKey('tenant_id', $rows[1]);
        self::assertNull($rows[1]['tenant_id']);
        self::assertSame($this->globex, $rows[1]['asked_for'] ?? null);
        self::assertSame('GET', $rows[0]['method'] ?? null);

        // And the key remembers being used.
        self::assertNotNull($this->connection->fetchOne('SELECT last_used_at FROM product_credentials WHERE product_id = :p', ['p' => $this->plan]));
    }

    public function testASessionOnAProductRouteAndAKeyOnAPersonRouteAreBothRefused(): void
    {
        $bearer = $this->bearer(['product.entitlements.read']);

        // A person's token where a key is expected: not a key, so nobody.
        $session = $this->request('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', ['Authorization' => 'Bearer ola-token']);
        self::assertSame(401, $session->getStatusCode());

        // A key where a person is expected: not a session, so nobody.
        $key = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $bearer, 'X-Product' => 'plan']);
        self::assertSame(401, $key->getStatusCode());

        // A key on a staff route: still nobody.
        self::assertSame(401, $this->request('GET', '/api/v1/staff/products', ['Authorization' => 'Bearer ' . $bearer])->getStatusCode());

        // Garbage that looks like a key.
        self::assertSame(401, $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', 'bpk_000000000000_nope')->getStatusCode());
    }

    public function testARevokedKeyIsToldSoAndStaysInTheList(): void
    {
        $issued = $this->decode($this->issue(['label' => 'staging', 'scopes' => ['product.entitlements.read']]));
        $credential = $issued['credential'] ?? null;
        self::assertIsArray($credential);
        $id = $credential['id'] ?? null;
        self::assertIsString($id);
        $bearer = $issued['bearer'] ?? null;
        self::assertIsString($bearer);

        self::assertSame(403, $this->request('DELETE', '/api/v1/staff/products/' . $this->plan . '/credentials/' . $id, ['Authorization' => 'Bearer sam-token'])->getStatusCode());

        $revoked = $this->request('DELETE', '/api/v1/staff/products/' . $this->plan . '/credentials/' . $id, $this->staff());
        self::assertSame(200, $revoked->getStatusCode());
        $after = $this->decode($revoked)['credential'] ?? null;
        self::assertIsArray($after);
        self::assertIsString($after['revoked_at'] ?? null);

        // Revoked is not invalid: the key is recognised and told why.
        $refused = $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', $bearer);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('PRODUCT_KEY_REVOKED', $this->errorOf($refused)['code'] ?? null);

        // Twice is the same answer, and the row is still listed.
        self::assertSame(200, $this->request('DELETE', '/api/v1/staff/products/' . $this->plan . '/credentials/' . $id, $this->staff())->getStatusCode());
        $listed = $this->decode($this->request('GET', '/api/v1/staff/products/' . $this->plan . '/credentials', $this->staff()))['credentials'] ?? null;
        self::assertIsArray($listed);
        self::assertCount(1, $listed);
        // Another product's key cannot be revoked through this one's address.
        self::assertSame(404, $this->request('DELETE', '/api/v1/staff/products/' . $this->atlas . '/credentials/' . $id, $this->staff())->getStatusCode());
        // Both revocations are on the trail: the second changed nothing, but
        // somebody asked, and the trail says who.
        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM staff_access_log WHERE action = 'REVOKE_PRODUCT_KEY'"));
    }

    public function testAnExpiredKeyIsToldSo(): void
    {
        $bearer = $this->bearer(['product.entitlements.read']);
        $this->connection->executeStatement("UPDATE product_credentials SET expires_at = now() - interval '1 minute'");

        $refused = $this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', $bearer);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('PRODUCT_KEY_EXPIRED', $this->errorOf($refused)['code'] ?? null);
    }

    public function testUsageIsRefusedAgainstTheQuotaBeforeTheWorkAndCountedOnce(): void
    {
        // Plan sells documents: a quota of two on Acme's entitlement.
        $feature = $this->id("INSERT INTO features (code, name, kind, unit) VALUES ('plan.documents', 'Documents', 'QUOTA', 'documents') RETURNING id");
        $this->connection->executeStatement(
            "INSERT INTO entitlements (tenant_id, product_id, feature_id, limit_value, source) VALUES (:t, :p, :f, 2, 'OVERRIDE')",
            ['t' => $this->acme, 'p' => $this->plan, 'f' => $feature],
        );
        $bearer = $this->bearer(['product.usage.write', 'product.entitlements.read']);
        $path = '/api/v1/product/tenants/' . $this->acme . '/usage';

        $first = $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 1, 'idempotency_key' => 'doc-1:created']);
        self::assertSame(201, $first->getStatusCode());
        $answer = $this->decode($first);
        self::assertTrue($answer['recorded'] ?? null);
        self::assertSame(1, $answer['used'] ?? null);
        self::assertSame(2, $answer['limit'] ?? null);

        // The same fact again — a retry — is not a second document.
        $again = $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 1, 'idempotency_key' => 'doc-1:created']);
        self::assertSame(200, $again->getStatusCode());
        self::assertFalse($this->decode($again)['recorded'] ?? null);
        self::assertSame(1, $this->decode($again)['used'] ?? null);

        self::assertSame(201, $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 1, 'idempotency_key' => 'doc-2:created'])->getStatusCode());

        // The third would cross the limit: refused, and nothing written.
        $refused = $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 1, 'idempotency_key' => 'doc-3:created']);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('QUOTA_EXCEEDED', $this->errorOf($refused)['code'] ?? null);
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM product_usage'));

        // Giving one back makes room: the sum of the deltas is the level.
        self::assertSame(201, $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => -1, 'idempotency_key' => 'doc-1:deleted'])->getStatusCode());
        self::assertSame(201, $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 1, 'idempotency_key' => 'doc-3:created'])->getStatusCode());

        // What was reported is what the entitlement route now reads as usage.
        $usage = $this->decode($this->asProduct('GET', '/api/v1/product/tenants/' . $this->acme . '/entitlements', $bearer))['usage'] ?? null;
        self::assertIsArray($usage);
        $documents = $usage[0] ?? null;
        self::assertIsArray($documents);
        self::assertSame('plan.documents', $documents['feature'] ?? null);
        self::assertTrue($documents['metered'] ?? null);
        self::assertSame(2, $documents['used'] ?? null);
        self::assertSame(0, $documents['remaining'] ?? null);

        // A feature the tenant holds no quota on is a limit of nothing.
        self::assertSame(403, $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.exports', 'quantity' => 1, 'idempotency_key' => 'x'])->getStatusCode());
        // And a zero delta says nothing.
        self::assertSame(400, $this->asProduct('POST', $path, $bearer, ['feature' => 'plan.documents', 'quantity' => 0, 'idempotency_key' => 'y'])->getStatusCode());
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function staff(): array
    {
        return ['Authorization' => 'Bearer ola-token'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function issue(array $body, string $token = 'ola-token', ?string $productId = null): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/staff/products/' . ($productId ?? $this->plan) . '/credentials',
            ['Authorization' => 'Bearer ' . $token],
            $this->json($body),
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function bearer(array $scopes): string
    {
        $bearer = $this->decode($this->issue(['label' => 'test', 'scopes' => $scopes]))['bearer'] ?? null;
        self::assertIsString($bearer);

        return $bearer;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function asProduct(string $method, string $path, string $bearer, ?array $body = null): ResponseInterface
    {
        return $this->request($method, $path, ['Authorization' => 'Bearer ' . $bearer], $body === null ? null : $this->json($body));
    }

    private function appoint(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
            ['user' => $userId, 'role' => $role],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
