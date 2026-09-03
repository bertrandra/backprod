<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\AuthProvider;
use App\Messaging\Domain\ConversationKind;
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
 * §12.3, through the real pipeline and the real database.
 *
 * Isolation comes first and behaviour second, deliberately. A conversation is
 * the first resource in this platform that two different tenants might
 * plausibly both touch, and the failure that matters is not "messaging does
 * not work" — it is "messaging works, for the wrong person".
 *
 * Nothing about participation, kind or ordering is faked. Those are claims
 * about foreign keys and a unique index; an in-memory repository would
 * satisfy every one of them by construction and prove nothing.
 */
#[CoversNothing]
final class MessagingTest extends DatabaseApiTestCase
{
    private string $productA = '';
    private string $productB = '';
    private string $tenantA = '';
    private string $tenantB = '';
    private string $mia = '';
    private string $max = '';
    private string $zoe = '';
    private string $sam = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->productA = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
        );
        $this->productB = $this->id(
            "INSERT INTO products (code, name, active) VALUES ('borea', 'Borea', true) RETURNING id",
        );
        $this->tenantA = $this->id("INSERT INTO tenants (name, slug) VALUES ('Acme', 'acme') RETURNING id");
        $this->tenantB = $this->id("INSERT INTO tenants (name, slug) VALUES ('Beta', 'beta') RETURNING id");

        $this->mia = $this->user('sub-mia', 'mia@acme.test');
        $this->max = $this->user('sub-max', 'max@acme.test');
        $this->zoe = $this->user('sub-zoe', 'zoe@beta.test');
        $this->sam = $this->user('sub-sam', 'sam@platform.test');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id)
                SELECT :user, id FROM platform_roles WHERE code = 'SUPPORT_ADMIN'
                SQL,
            ['user' => $this->sam],
        );

        // Real membership rows: the check that stops a stranger being added
        // to a thread is the security boundary here, so it is answered by
        // the real repository against real rows. A fake that ignored tenant
        // and product would make that test pass for the wrong reason.
        $this->member($this->tenantA, $this->productA, $this->mia);
        $this->member($this->tenantA, $this->productA, $this->max);
        $this->member($this->tenantA, $this->productB, $this->mia);
        $this->member($this->tenantB, $this->productA, $this->zoe);

        $talk = ['messages.read', 'messages.write'];

        $this->override([
            AuthProvider::class => new FakeAuthProvider([
                'mia-token' => 'sub-mia',
                'max-token' => 'sub-max',
                'zoe-token' => 'sub-zoe',
                'sam-token' => 'sub-sam',
            ]),

            ProductRepository::class => new InMemoryProductRepository([
                new Product($this->productA, 'atlas', 'Atlas', true),
                new Product($this->productB, 'borea', 'Borea', true),
            ]),

            TenantMembershipRepository::class => new InMemoryTenantMembershipRepository([
                new TenantMembership($this->tenantA, $this->mia, $this->productA, ['TENANT_ADMIN'], $talk),
                new TenantMembership($this->tenantA, $this->max, $this->productA, ['USER'], $talk),
                // Mia is also in Acme under a second product, which is what
                // makes the cross-product test meaningful.
                new TenantMembership($this->tenantA, $this->mia, $this->productB, ['TENANT_ADMIN'], $talk),
                new TenantMembership($this->tenantB, $this->zoe, $this->productA, ['TENANT_ADMIN'], $talk),
            ]),
        ]);
    }

    // --- Isolation, before anything else -------------------------------------

    public function testAnotherTenantCannotReadTheThread(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        // Zoe is a TENANT_ADMIN of Beta with every messaging permission. What
        // she lacks is any relationship to this conversation.
        $response = $this->get('/api/v1/conversations/' . $conversation, 'zoe-token');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('CONVERSATION_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testAnotherProductOfTheSameTenantCannotReadTheThread(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        // Mia herself, in the same tenant, under a different product. The
        // product is the root context: the thread is not hers to read here.
        $response = $this->request('GET', '/api/v1/conversations/' . $conversation, [
            'Authorization' => 'Bearer mia-token',
            'X-Product' => 'borea',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAMemberOfTheTenantWhoIsNotAParticipantIsRefused(): void
    {
        $conversation = $this->startThread('mia-token', 'Private');

        // Max belongs to Acme under Atlas and was not invited. "Not yours"
        // and "does not exist" answer identically on purpose, so a member
        // cannot probe for threads they are not in.
        $response = $this->get('/api/v1/conversations/' . $conversation, 'max-token');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('CONVERSATION_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testWritingToAThreadOneIsNotInIsRefused(): void
    {
        $conversation = $this->startThread('mia-token', 'Private');

        $response = $this->post(
            '/api/v1/conversations/' . $conversation . '/messages',
            ['body' => 'let me in'],
            'max-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching('SELECT count(*) FROM messages'));
    }

    public function testSomebodyOutsideTheTenantCannotBeAddedToAThread(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        // The plainest form of the leak: adding a stranger to a conversation
        // would hand them a view of this tenant's data.
        $response = $this->post(
            '/api/v1/conversations/' . $conversation . '/participants',
            ['user_id' => $this->zoe],
            'mia-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('MEMBER_NOT_FOUND', $this->errorOf($response)['code'] ?? null);
    }

    public function testStaffCannotJoinAnInternalThread(): void
    {
        $conversation = $this->startThread('mia-token', 'Internal only');

        // Not a permission check that could be forgotten: the composite
        // foreign key onto (conversations.id, kind) refuses the row.
        $response = $this->post(
            '/api/v1/staff/conversations/' . $conversation . '/messages',
            ['body' => 'hello from support'],
            'sam-token',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowsMatching(
            "SELECT count(*) FROM conversation_participants WHERE participant_kind = 'STAFF'",
        ));
    }

    public function testStaffCannotSeeInternalThreadsInTheirListing(): void
    {
        $this->startThread('mia-token', 'Internal only');
        $support = $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);

        $response = $this->get('/api/v1/staff/conversations', 'sam-token');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame(1, $body['total'] ?? null);

        $conversations = $body['conversations'] ?? null;
        self::assertIsArray($conversations);

        $first = $conversations[0] ?? null;
        self::assertIsArray($first);
        self::assertSame($support, $first['id'] ?? null);
    }

    public function testATenantMemberCannotReachTheStaffSurface(): void
    {
        $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);

        $response = $this->get('/api/v1/staff/conversations', 'mia-token');

        self::assertSame(403, $response->getStatusCode());
    }

    // --- What messaging actually does ----------------------------------------

    public function testATenantMemberTalksToAnotherInTheSameTenant(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey', ConversationKind::INTERNAL, [$this->max]);

        $posted = $this->post(
            '/api/v1/conversations/' . $conversation . '/messages',
            ['body' => 'Survey booked for Tuesday'],
            'mia-token',
        );
        self::assertSame(201, $posted->getStatusCode());
        self::assertSame(1, $this->decode($posted)['seq'] ?? null);

        // Max was named as a participant, so the thread is his to read.
        $seen = $this->get('/api/v1/conversations/' . $conversation . '/messages', 'max-token');
        self::assertSame(200, $seen->getStatusCode());

        $messages = $this->decode($seen)['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);

        $first = $messages[0];
        self::assertIsArray($first);
        self::assertSame('Survey booked for Tuesday', $first['body'] ?? null);
        self::assertSame('MEMBER', $first['author_kind'] ?? null);
    }

    public function testTheSequenceIsPerThreadAndConsecutive(): void
    {
        $one = $this->startThread('mia-token', 'First', ConversationKind::INTERNAL, [$this->max]);
        $two = $this->startThread('mia-token', 'Second');

        $this->post('/api/v1/conversations/' . $one . '/messages', ['body' => 'a'], 'mia-token');
        $this->post('/api/v1/conversations/' . $one . '/messages', ['body' => 'b'], 'max-token');
        $posted = $this->post('/api/v1/conversations/' . $two . '/messages', ['body' => 'c'], 'mia-token');

        self::assertSame([1, 2], $this->seqsOf($one));
        // A second thread starts again at 1: seq is per conversation, which
        // is what lets a watermark mean something.
        self::assertSame(1, $this->decode($posted)['seq'] ?? null);
    }

    public function testSinceSeqReturnsOnlyWhatFollowed(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'one'], 'mia-token');
        $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'two'], 'mia-token');
        $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'three'], 'mia-token');

        $response = $this->get(
            '/api/v1/conversations/' . $conversation . '/messages?since_seq=2',
            'mia-token',
        );

        $messages = $this->decode($response)['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);

        $only = $messages[0];
        self::assertIsArray($only);
        self::assertSame(3, $only['seq'] ?? null);
    }

    public function testTheReadWatermarkNeverGoesBackwards(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        foreach (['one', 'two', 'three'] as $body) {
            $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => $body], 'mia-token');
        }

        $this->post('/api/v1/conversations/' . $conversation . '/read', ['seq' => 3], 'mia-token');
        // A second tab, scrolled further back, reports an older position.
        $response = $this->post('/api/v1/conversations/' . $conversation . '/read', ['seq' => 1], 'mia-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $this->decode($response)['last_read_seq'] ?? null);
    }

    public function testUnreadIsCountedFromTheWatermark(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey', ConversationKind::INTERNAL, [$this->max]);

        foreach (['one', 'two'] as $body) {
            $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => $body], 'mia-token');
        }

        $listed = $this->decode($this->get('/api/v1/conversations', 'max-token'));
        $conversations = $listed['conversations'] ?? null;
        self::assertIsArray($conversations);

        $first = $conversations[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(2, $first['unread'] ?? null);
    }

    public function testADeletedMessageLosesItsBodyAndKeepsItsPlace(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        $posted = $this->decode(
            $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'oops'], 'mia-token'),
        );
        $messageId = $posted['id'] ?? null;
        self::assertIsString($messageId);

        $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'after'], 'mia-token');

        $deleted = $this->request(
            'DELETE',
            '/api/v1/conversations/' . $conversation . '/messages/' . $messageId,
            $this->headers('mia-token'),
        );
        self::assertSame(204, $deleted->getStatusCode());

        // Really erased — unlike an invoice, which §26 requires be kept.
        self::assertSame(1, $this->rowsMatching(
            "SELECT count(*) FROM messages WHERE body = '' AND deleted_at IS NOT NULL",
        ));
        // The thread keeps its order: the tombstone still holds seq 1.
        self::assertSame([1, 2], $this->seqsOf($conversation));
    }

    public function testOnlyTheAuthorMayDeleteAMessage(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey', ConversationKind::INTERNAL, [$this->max]);

        $posted = $this->decode(
            $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'mine'], 'mia-token'),
        );
        $messageId = $posted['id'] ?? null;
        self::assertIsString($messageId);

        $response = $this->request(
            'DELETE',
            '/api/v1/conversations/' . $conversation . '/messages/' . $messageId,
            $this->headers('max-token'),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('MESSAGE_NOT_DELETABLE', $this->errorOf($response)['code'] ?? null);
    }

    public function testAClosedThreadTakesNoMoreMessages(): void
    {
        $conversation = $this->startThread('mia-token', 'Roof survey');

        $this->post('/api/v1/conversations/' . $conversation . '/close', [], 'mia-token');

        $response = $this->post(
            '/api/v1/conversations/' . $conversation . '/messages',
            ['body' => 'one more'],
            'mia-token',
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONVERSATION_CLOSED', $this->errorOf($response)['code'] ?? null);
    }

    // --- The platform talking to a customer ----------------------------------

    public function testSupportRepliesToACustomerAndTheCustomerSeesIt(): void
    {
        $conversation = $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);
        $this->post('/api/v1/conversations/' . $conversation . '/messages', ['body' => 'Why 20%?'], 'mia-token');

        $reply = $this->post(
            '/api/v1/staff/conversations/' . $conversation . '/messages',
            ['body' => 'French standard rate.'],
            'sam-token',
        );

        self::assertSame(201, $reply->getStatusCode());
        self::assertSame('STAFF', $this->decode($reply)['author_kind'] ?? null);

        // The customer reads it on their own surface, in the same thread.
        $seen = $this->decode(
            $this->get('/api/v1/conversations/' . $conversation . '/messages', 'mia-token'),
        );
        $messages = $seen['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(2, $messages);

        $second = $messages[1];
        self::assertIsArray($second);
        self::assertSame('French standard rate.', $second['body'] ?? null);
        self::assertSame('STAFF', $second['author_kind'] ?? null);
    }

    public function testEveryStaffReadOfACustomerThreadIsRecorded(): void
    {
        $conversation = $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);

        $this->get('/api/v1/staff/conversations/' . $conversation, 'sam-token');

        // Non-negotiable #21: crossing the boundary is never silent, and the
        // row says on what grounds.
        self::assertSame(1, $this->rowsMatching(
            <<<'SQL'
                SELECT count(*) FROM staff_access_log
                 WHERE resource_type = 'conversation'
                   AND action = 'READ'
                   AND permission = 'support.read'
                SQL,
        ));
    }

    public function testAStaffReplyIsRecordedAsAWrite(): void
    {
        $conversation = $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);

        $this->post(
            '/api/v1/staff/conversations/' . $conversation . '/messages',
            ['body' => 'Looking into it.'],
            'sam-token',
        );

        self::assertSame(1, $this->rowsMatching(
            <<<'SQL'
                SELECT count(*) FROM staff_access_log
                 WHERE action = 'WRITE' AND permission = 'support.respond'
                SQL,
        ));
    }

    public function testATenantCannotEjectSupportFromItsOwnSupportThread(): void
    {
        $conversation = $this->startThread('mia-token', 'Invoice question', ConversationKind::SUPPORT);
        $this->post(
            '/api/v1/staff/conversations/' . $conversation . '/messages',
            ['body' => 'Looking into it.'],
            'sam-token',
        );

        $response = $this->request(
            'DELETE',
            '/api/v1/conversations/' . $conversation . '/participants/' . $this->sam,
            $this->headers('mia-token'),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('PARTICIPANT_NOT_REMOVABLE', $this->errorOf($response)['code'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * @param list<string> $participants
     */
    private function startThread(
        string $token,
        string $subject,
        string $kind = ConversationKind::INTERNAL,
        array $participants = [],
    ): string {
        $response = $this->post(
            '/api/v1/conversations',
            ['subject' => $subject, 'kind' => $kind, 'participants' => $participants],
            $token,
        );

        self::assertSame(201, $response->getStatusCode());

        $id = $this->decode($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<int>
     */
    private function seqsOf(string $conversationId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT seq FROM messages WHERE conversation_id = :id ORDER BY seq',
            ['id' => $conversationId],
        );

        return array_map(static fn (mixed $seq): int => is_numeric($seq) ? (int) $seq : 0, $rows);
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, $this->headers($token));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $token): ResponseInterface
    {
        return $this->request('POST', $path, $this->headers($token), $this->json($body));
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'X-Product' => 'atlas'];
    }

    private function member(string $tenantId, string $productId, string $userId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_members (tenant_id, user_id, product_id)
                VALUES (:tenant, :user, :product)
                SQL,
            ['tenant' => $tenantId, 'user' => $userId, 'product' => $productId],
        );
    }

    private function user(string $subject, string $email): string
    {
        return $this->id(
            'INSERT INTO users (auth_subject, email) VALUES (:subject, :email) RETURNING id',
            ['subject' => $subject, 'email' => $email],
        );
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
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
