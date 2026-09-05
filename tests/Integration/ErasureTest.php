<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\InMemoryProductRepository;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Infrastructure\InMemoryTenantMembershipRepository;
use App\Tests\Support\FakeAuthProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

/**
 * M8's second exit criterion: a deletion request preserves records under
 * legal retention (non-negotiables #14 and #15).
 *
 * The two obligations overlap and neither wins outright. "Delete everything
 * about me" and "keep your accounts" are both real, so the tests come in
 * pairs: for each thing that must go, something that must stay.
 *
 * The uncomfortable one is the invoice. Its customer snapshot still names the
 * person afterwards, and that is correct — altering an issued invoice
 * falsifies a legal document, which is a worse answer than keeping it. A test
 * asserting the name is gone would be asserting a bug.
 *
 * Refusals first (§37.4).
 */
#[CoversNothing]
final class ErasureTest extends DatabaseApiTestCase
{
    private string $product = '';
    private string $tenant = '';
    private string $operator = '';
    private string $supporter = '';
    private string $subject = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->tenant = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");

        $this->operator = $this->user('sub-ops', 'ops@platform.test', 'Ops');
        $this->supporter = $this->user('sub-sam', 'sam@platform.test', 'Sam');
        $this->subject = $this->user('sub-mia', 'mia@acme.test', 'Mia Dupont');

        $this->grantRole($this->operator, 'PLATFORM_ADMIN');
        $this->grantRole($this->supporter, 'SUPPORT_ADMIN');

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'ops-token' => 'sub-ops',
                'sam-token' => 'sub-sam',
                'mia-token' => 'sub-mia',
            ]),
            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->product, 'atlas', 'Atlas', true),
            ]),
            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership(
                    $this->tenant,
                    $this->subject,
                    $this->product,
                    ['TENANT_ADMIN'],
                    ['tenant.read', 'tenant.manage'],
                ),
            ]),
        ]);
    }

    // --- Who may not ------------------------------------------------------------

    public function testATenantAdminCannotEraseAnybody(): void
    {
        $response = $this->erase($this->subject, 'mia-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->errorOf($response)['code'] ?? null);
    }

    /**
     * Support talks to the person who asks to be forgotten, which is exactly
     * why they must not be the one to do it.
     */
    public function testSupportCannotErase(): void
    {
        self::assertSame(403, $this->erase($this->subject, 'sam-token')->getStatusCode());
    }

    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        self::assertSame(401, $this->request(
            'POST',
            '/api/v1/admin/erasures',
            [],
            $this->json(['user_id' => $this->subject]),
        )->getStatusCode());
    }

    public function testErasingSomebodyWhoDoesNotExistIsNotFound(): void
    {
        $response = $this->erase('11111111-1111-4111-8111-111111111111', 'ops-token');

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * A second run would write a second and emptier record of the same act,
     * and two accounts of one erasure is worse evidence than one.
     */
    public function testNobodyIsErasedTwice(): void
    {
        self::assertSame(200, $this->erase($this->subject, 'ops-token')->getStatusCode());

        $again = $this->erase($this->subject, 'ops-token');

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ALREADY_ERASED', $this->errorOf($again)['code'] ?? null);
    }

    // --- What goes ---------------------------------------------------------------

    public function testTheirIdentityStopsNamingThem(): void
    {
        $this->erase($this->subject, 'ops-token');

        $row = $this->connection->fetchAssociative(
            'SELECT email, display_name, auth_subject, erased_at FROM users WHERE id = :id',
            ['id' => $this->subject],
        );

        self::assertIsArray($row);
        self::assertNull($row['email']);
        self::assertNull($row['display_name']);
        self::assertNotNull($row['erased_at']);
        self::assertStringStartsWith('erased:', (string) $row['auth_subject']);
    }

    /**
     * The tombstone is not decoration: a token presents the identity
     * provider's subject, never one of ours, so an erased row can never be
     * matched again.
     */
    public function testTheirTokenNoLongerFindsAnybody(): void
    {
        $this->erase($this->subject, 'ops-token');

        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM users WHERE auth_subject = 'sub-mia'",
        ));
    }

    public function testTheirWordsAreGoneButTheConversationKeepsItsShape(): void
    {
        $message = $this->messageFrom($this->subject, 'My name is Mia and I live at 12 rue de la Paix');

        $this->erase($this->subject, 'ops-token');

        $row = $this->connection->fetchAssociative(
            'SELECT body, seq, deleted_at FROM messages WHERE id = :id',
            ['id' => $message],
        );

        self::assertIsArray($row);
        self::assertSame('', $row['body']);
        // The message keeps its place, so the thread does not develop a hole
        // where a reply used to be.
        self::assertSame(1, (int) $row['seq']);
        self::assertNotNull($row['deleted_at']);
    }

    // --- What stays --------------------------------------------------------------

    /**
     * The uncomfortable one, and the point of #15. The invoice still names
     * them, because altering an issued invoice falsifies a legal document.
     */
    public function testAnIssuedInvoiceStillSaysWhoItWasFor(): void
    {
        $this->invoice();

        $this->erase($this->subject, 'ops-token');

        self::assertSame('Mia Dupont', $this->connection->fetchOne(
            "SELECT customer_snapshot->>'legal_name' FROM invoices",
        ));
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM invoices WHERE status = 'ISSUED'",
        ));
    }

    /**
     * §30's trail survives with its actor forgotten: what happened is kept,
     * who did it is not. This is the path M8 part 1 built and nothing has
     * exercised until now.
     */
    public function testTheAuditTrailKeepsWhatHappenedAndForgetsWhoDidIt(): void
    {
        $this->auditEntry($this->subject);

        $this->erase($this->subject, 'ops-token');

        $row = $this->connection->fetchAssociative(
            "SELECT user_id, actor_forgotten_at, detail->>'number' AS number"
            . " FROM audit_log WHERE action = 'invoice.issued'",
        );

        self::assertIsArray($row);
        self::assertNull($row['user_id']);
        self::assertNotNull($row['actor_forgotten_at']);
        // Untouched: the trigger permits the actor to be forgotten and
        // nothing else, so the record of the act is byte-identical.
        self::assertSame('F-2026-001', $row['number']);
    }

    /**
     * The receipt. A caller told only "done" would have to take on trust that
     * the accounting records survived — the one thing about this operation
     * nobody should have to take on trust.
     */
    public function testTheResponseSaysWhatWasKeptAndWhy(): void
    {
        $this->auditEntry($this->subject);

        $erasure = $this->decode($this->erase($this->subject, 'ops-token'))['erasure'] ?? null;

        self::assertIsArray($erasure);

        $retained = $erasure['retained'] ?? null;
        self::assertIsArray($retained);

        $audit = $retained['audit_entries'] ?? null;
        self::assertIsArray($audit);
        self::assertSame(1, $audit['count'] ?? null);
        self::assertSame('audit_trail', $audit['ground'] ?? null);
    }

    /**
     * Written inside the erasure's own transaction, so an erasure can never
     * commit unattributed.
     */
    public function testTheErasureIsItselfRecordedAgainstWhoDidIt(): void
    {
        $this->erase($this->subject, 'ops-token');

        $row = $this->connection->fetchAssociative(
            "SELECT user_id, subject_id FROM audit_log WHERE action = 'privacy.erased'",
        );

        self::assertIsArray($row);
        // The administrator, never the person erased — whose actor rows were
        // being cleared in the same breath.
        self::assertSame($this->operator, $row['user_id']);
        self::assertSame($this->subject, $row['subject_id']);
    }

    public function testTheRequestIsFiledWithBothHalvesOfWhatItDid(): void
    {
        $this->erase($this->subject, 'ops-token');

        $row = $this->connection->fetchAssociative(
            'SELECT subject_user_id, requested_by, erased, retained FROM erasure_requests',
        );

        self::assertIsArray($row);
        self::assertSame($this->subject, $row['subject_user_id']);
        self::assertSame($this->operator, $row['requested_by']);
        self::assertStringContainsString('identity', (string) $row['erased']);
        self::assertStringContainsString('accounting_record', (string) $row['retained']);
    }

    // --- Helpers -----------------------------------------------------------------

    private function erase(string $userId, string $token): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/admin/erasures',
            ['Authorization' => 'Bearer ' . $token],
            $this->json(['user_id' => $userId]),
        );
    }

    private function invoice(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO invoices (tenant_id, product_id, number, status, currency, issued_at,
                                      net_minor_units, vat_minor_units, gross_minor_units,
                                      supplier_snapshot, customer_snapshot)
                VALUES (:tenant, :product, 'F-2026-001', 'ISSUED', 'EUR', now(),
                        10000, 2000, 12000, '{}',
                        CAST('{"legal_name":"Mia Dupont","email":"mia@acme.test"}' AS jsonb))
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product],
        );
    }

    private function auditEntry(string $userId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO audit_log (action, subject_type, tenant_id, product_id, user_id, detail)
                VALUES ('invoice.issued', 'invoice', :tenant, :product, :user,
                        CAST('{"number":"F-2026-001"}' AS jsonb))
                SQL,
            ['tenant' => $this->tenant, 'product' => $this->product, 'user' => $userId],
        );
    }

    private function messageFrom(string $userId, string $body): string
    {
        $conversation = $this->id(
            'INSERT INTO conversations (tenant_id, product_id, kind, subject, created_by)'
            . " VALUES (:tenant, :product, 'INTERNAL', 'Hello', :user) RETURNING id",
            ['tenant' => $this->tenant, 'product' => $this->product, 'user' => $userId],
        );

        $this->connection->executeStatement(
            'INSERT INTO conversation_participants'
            . ' (conversation_id, conversation_kind, user_id, participant_kind)'
            . " SELECT id, kind, :user, 'MEMBER' FROM conversations WHERE id = :conversation",
            ['conversation' => $conversation, 'user' => $userId],
        );

        return $this->id(
            'INSERT INTO messages (conversation_id, seq, author_user_id, author_kind, body)'
            . " VALUES (:conversation, 1, :user, 'MEMBER', :body) RETURNING id",
            ['conversation' => $conversation, 'user' => $userId, 'body' => $body],
        );
    }

    private function grantRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id)'
            . ' SELECT :user, id FROM platform_roles WHERE code = :role',
            ['user' => $userId, 'role' => $role],
        );
    }

    private function user(string $subject, string $email, string $name): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email, display_name)'
            . ' VALUES (:subject, :email, :name) RETURNING id',
            ['subject' => $subject, 'email' => $email, 'name' => $name],
        );
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        self::assertIsNumeric($count);

        return (int) $count;
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
