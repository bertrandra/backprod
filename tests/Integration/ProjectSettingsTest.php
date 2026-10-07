<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Project\Domain\DocumentLimit;
use App\Project\Domain\DocumentPolicy;
use App\Shared\Exceptions\PayloadTooLargeException;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * How large a project document may be (2026-10-07): the operator's choice
 * from the setup menu, the default until somebody makes it, and read by the
 * policy that refuses a save.
 */
#[CoversNothing]
final class ProjectSettingsTest extends DatabaseApiTestCase
{
    private const PATH = '/api/v1/staff/projects/settings';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['sub-ola', 'ola@platform.test', 'PLATFORM_ADMIN'], ['sub-sam', 'sam@platform.test', 'SUPPORT_ADMIN']] as [$subject, $email, $role]) {
            $user = $this->connection->fetchOne(
                'INSERT INTO users (auth_subject, email) VALUES (:s, :e) RETURNING id',
                ['s' => $subject, 'e' => $email],
            );
            self::assertIsString($user);
            $this->connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id) SELECT :user, id FROM platform_roles WHERE code = :role',
                ['user' => $user, 'role' => $role],
            );
        }

        $this->override([
            AuthProvider::class => new FakeAuthProvider(['ola-token' => 'sub-ola', 'sam-token' => 'sub-sam']),
        ]);
    }

    /**
     * Nothing decided is the default, never "no limit" — and no row, so that
     * "nobody has decided" stays distinguishable from a decision.
     */
    public function testNobodyHasDecidedAndSoTheDefaultHolds(): void
    {
        $shown = $this->decode($this->request('GET', self::PATH, ['Authorization' => 'Bearer ola-token']));

        self::assertSame(DocumentLimit::DEFAULT_MIB, $shown['max_document_mib'] ?? null);
        self::assertSame(DocumentLimit::DEFAULT_MIB, $shown['default_mib'] ?? null);
        self::assertSame(DocumentLimit::MINIMUM_MIB, $shown['minimum_mib'] ?? null);
        self::assertSame(DocumentLimit::MAXIMUM_MIB, $shown['maximum_mib'] ?? null);
        self::assertArrayHasKey('host_upload_bytes', $shown);
        self::assertIsBool($shown['host_overrules'] ?? null);
        self::assertIsBool($shown['host_overrules'] ?? null);

        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM platform_settings WHERE key = 'projects'"));
    }

    /**
     * What the console sets is what the next save is judged by — proved on
     * the policy itself, not only on the setting reading back.
     */
    public function testTheLimitSetIsTheLimitASaveIsJudgedBy(): void
    {
        $set = $this->request('PUT', self::PATH, ['Authorization' => 'Bearer ola-token'], $this->json(['max_document_mib' => 1]));
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        self::assertSame(1, $this->decode($set)['max_document_mib'] ?? null);
        self::assertSame(1, $this->decode($this->request('GET', self::PATH, ['Authorization' => 'Bearer ola-token']))['max_document_mib'] ?? null);

        // Two mebibytes in strings each under the 64 KiB rule, so the only
        // thing that can refuse it is the size.
        $document = new \stdClass();
        for ($i = 0; $i < 40; ++$i) {
            $document->{'chunk' . $i} = str_repeat('x', 60_000);
        }

        $policy = $this->container()->get(DocumentPolicy::class);
        self::assertInstanceOf(DocumentPolicy::class, $policy);

        try {
            $policy->assertStorable($document);
            self::fail('A document over the configured limit was accepted.');
        } catch (PayloadTooLargeException $refused) {
            self::assertSame(DocumentLimit::BYTES_PER_MIB, $refused->details()['limit_bytes'] ?? null);
        }

        // Raised again, the same document fits.
        $this->request('PUT', self::PATH, ['Authorization' => 'Bearer ola-token'], $this->json(['max_document_mib' => 8]));
        $policy->assertStorable($document);
    }

    /**
     * A whole number within bounds, and nothing else read as one. A refusal
     * leaves the setting where it was.
     */
    public function testOnlyAWholeNumberWithinBoundsIsAccepted(): void
    {
        $this->request('PUT', self::PATH, ['Authorization' => 'Bearer ola-token'], $this->json(['max_document_mib' => 6]));

        foreach ([0, DocumentLimit::MAXIMUM_MIB + 1, '8', 8.5, null] as $bogus) {
            $refused = $this->request('PUT', self::PATH, ['Authorization' => 'Bearer ola-token'], $this->json(['max_document_mib' => $bogus]));
            self::assertSame(400, $refused->getStatusCode(), var_export($bogus, true));
        }

        self::assertSame(6, $this->decode($this->request('GET', self::PATH, ['Authorization' => 'Bearer ola-token']))['max_document_mib'] ?? null);
    }

    /** Setting the platform up: `staff.products.manage`, which support does not hold. */
    public function testItAnswersToThePlatformAdministrator(): void
    {
        self::assertSame(403, $this->request('GET', self::PATH, ['Authorization' => 'Bearer sam-token'])->getStatusCode());
        self::assertSame(403, $this->request('PUT', self::PATH, ['Authorization' => 'Bearer sam-token'], $this->json(['max_document_mib' => 8]))->getStatusCode());
        self::assertSame(401, $this->request('PUT', self::PATH, [], $this->json(['max_document_mib' => 8]))->getStatusCode());
    }
}
