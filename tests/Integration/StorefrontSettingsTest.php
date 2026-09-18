<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Tests\Support\FakeAuthProvider;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * How a self-service sign-up ends (2026-09-18): the platform's choice,
 * read by every storefront root so the form can say what follows.
 */
#[CoversNothing]
final class StorefrontSettingsTest extends DatabaseApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $atlas = $this->id("INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id");
        $acme = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id");
        TestDatabase::assignProduct($this->connection, $acme, $atlas);

        foreach ([['sub-ola', 'ola@platform.test', 'PLATFORM_ADMIN'], ['sub-sam', 'sam@platform.test', 'SUPPORT_ADMIN']] as [$subject, $email, $role]) {
            $user = $this->id('INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id', ['s' => $subject, 'e' => $email]);
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    public function testPayingThereAndThenIsTheDefaultAndEveryRootSaysSo(): void
    {
        $shown = $this->decode($this->request('GET', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer ola-token']));
        self::assertSame('PAY', $shown['after_sign_up'] ?? null);

        $root = $this->decode($this->request('GET', '/api/v1/public/tenant?tenant=acme'));
        self::assertIsArray($root['tenant'] ?? null);
        self::assertSame('PAY', $root['tenant']['after_sign_up'] ?? null);
    }

    public function testTheApplicationFirstIsAChoiceThePublicRootReflectsAtOnce(): void
    {
        $set = $this->request('PUT', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['after_sign_up' => 'CATALOGUE']));
        self::assertSame(200, $set->getStatusCode());
        self::assertSame('CATALOGUE', $this->decode($set)['after_sign_up'] ?? null);

        $root = $this->decode($this->request('GET', '/api/v1/public/tenant?tenant=acme'));
        self::assertIsArray($root['tenant'] ?? null);
        self::assertSame('CATALOGUE', $root['tenant']['after_sign_up'] ?? null);

        $bogus = $this->request('PUT', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer ola-token'], $this->json(['after_sign_up' => 'SOMEWHERE']));
        self::assertSame(400, $bogus->getStatusCode());
        self::assertSame('CATALOGUE', $this->decode($this->request('GET', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer ola-token']))['after_sign_up'] ?? null);
    }

    public function testOnlyTheStorefrontsPermissionMovesIt(): void
    {
        self::assertSame(403, $this->request('PUT', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer sam-token'], $this->json(['after_sign_up' => 'CATALOGUE']))->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v1/staff/storefront/settings', ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(401, $this->request('PUT', '/api/v1/staff/storefront/settings', [], $this->json(['after_sign_up' => 'CATALOGUE']))->getStatusCode());
    }

    /** @param array<string, mixed> $parameters */
    private function id(string $sql, array $parameters = []): string
    {
        $id = $this->connection->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
