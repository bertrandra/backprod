<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure;

use App\Messaging\Domain\Conversation;
use App\Messaging\Domain\ConversationRepository;
use App\Messaging\Domain\Message;
use App\Messaging\Domain\Participant;
use App\Messaging\Domain\ParticipantKind;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

/**
 * Conversations in PostgreSQL.
 *
 * Two things here are worth reading twice.
 *
 * {@see self::post()} allocates `seq` under a row lock on the conversation.
 * That serialises posting to one thread and nothing else — unlike the table
 * lock invoice numbering needs (§25), because `seq` must be unique and
 * monotone but not gapless, and a table lock to avoid gaps nobody is entitled
 * to would make every chat message wait behind every other.
 *
 * {@see self::markRead()} moves the watermark with GREATEST, so a client
 * reporting a stale position cannot rewind it. Two tabs open at different
 * scroll depths is the ordinary case, not an error.
 */
final class PostgresConversationRepository implements ConversationRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, kind, subject, status,
        created_by, closed_at, created_at, updated_at
        SQL;

    /** The same columns, aliased, for the join that scopes by participation. */
    private const ALIASED_COLUMNS = <<<'SQL'
        c.id, c.tenant_id, c.product_id, c.kind, c.subject, c.status,
        c.created_by, c.closed_at, c.created_at, c.updated_at
        SQL;

    private const MESSAGE_COLUMNS = <<<'SQL'
        id, conversation_id, seq, author_user_id, author_kind,
        body, created_at, edited_at, deleted_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listForParticipant(
        string $tenantId,
        string $productId,
        string $userId,
        int $limit,
        int $offset,
    ): array {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($userId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::ALIASED_COLUMNS . <<<'SQL'
                  FROM conversations c
                  JOIN conversation_participants p ON p.conversation_id = c.id
                 WHERE c.tenant_id = :tenant
                   AND c.product_id = :product
                   AND p.user_id = :user
                 ORDER BY c.updated_at DESC, c.id
                 LIMIT :limit OFFSET :offset
                SQL,
            [
                'tenant' => $tenantId,
                'product' => $productId,
                'user' => $userId,
                'limit' => $limit,
                'offset' => $offset,
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toConversation(...), $rows);
    }

    public function countForParticipant(string $tenantId, string $productId, string $userId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($userId)) {
            return 0;
        }

        return $this->countOf(
            <<<'SQL'
                SELECT count(*)
                  FROM conversations c
                  JOIN conversation_participants p ON p.conversation_id = c.id
                 WHERE c.tenant_id = :tenant AND c.product_id = :product AND p.user_id = :user
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId],
        );
    }

    public function find(string $tenantId, string $productId, string $conversationId): ?Conversation
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($conversationId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM conversations
                 WHERE id = :id AND tenant_id = :tenant AND product_id = :product
                SQL,
            ['id' => $conversationId, 'tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : self::toConversation($row);
    }

    public function findSupport(string $conversationId): ?Conversation
    {
        if (!Uuid::isValid($conversationId)) {
            return null;
        }

        // The kind is part of the query, not a check afterwards. An INTERNAL
        // thread is not staff's to read, and a repository that returned one
        // would leave that rule to whoever remembered to write the `if`.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM conversations
                 WHERE id = :id AND kind = 'SUPPORT'
                SQL,
            ['id' => $conversationId],
        );

        return $row === false ? null : self::toConversation($row);
    }

    public function listSupport(?string $tenantId, int $limit, int $offset): array
    {
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM conversations
                 WHERE kind = 'SUPPORT'
                   AND (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                 ORDER BY updated_at DESC, id
                 LIMIT :limit OFFSET :offset
                SQL,
            ['tenant' => $tenantId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toConversation(...), $rows);
    }

    public function countSupport(?string $tenantId): int
    {
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            return 0;
        }

        return $this->countOf(
            <<<'SQL'
                SELECT count(*) FROM conversations
                 WHERE kind = 'SUPPORT'
                   AND (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                SQL,
            ['tenant' => $tenantId],
        );
    }

    public function start(
        string $tenantId,
        string $productId,
        string $kind,
        string $subject,
        string $creatorId,
        array $memberIds,
    ): Conversation {
        return $this->connection->transactional(function () use (
            $tenantId,
            $productId,
            $kind,
            $subject,
            $creatorId,
            $memberIds,
        ): Conversation {
            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO conversations (tenant_id, product_id, kind, subject, created_by)
                    VALUES (:tenant, :product, :kind, :subject, :creator)
                    RETURNING id
                    SQL,
                [
                    'tenant' => $tenantId,
                    'product' => $productId,
                    'kind' => $kind,
                    'subject' => $subject,
                    'creator' => $creatorId,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to start a conversation.');
            }

            // The creator first, then the rest — deduplicated, because naming
            // yourself among the participants is a reasonable thing for a
            // client to do and should not collide on the primary key.
            $this->insertParticipant($id, $kind, $creatorId, ParticipantKind::MEMBER);

            foreach (array_unique($memberIds) as $memberId) {
                if ($memberId !== $creatorId) {
                    $this->insertParticipant($id, $kind, $memberId, ParticipantKind::MEMBER);
                }
            }

            $conversation = $this->find($tenantId, $productId, $id);

            if ($conversation === null) {
                throw new RuntimeException('The conversation vanished during the transaction that created it.');
            }

            return $conversation;
        });
    }

    public function participant(string $conversationId, string $userId): ?Participant
    {
        if (!Uuid::isValid($conversationId) || !Uuid::isValid($userId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT conversation_id, user_id, participant_kind, last_read_seq, joined_at, left_at
                  FROM conversation_participants
                 WHERE conversation_id = :conversation AND user_id = :user
                SQL,
            ['conversation' => $conversationId, 'user' => $userId],
        );

        return $row === false ? null : self::toParticipant($row);
    }

    public function participants(string $conversationId): array
    {
        if (!Uuid::isValid($conversationId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT conversation_id, user_id, participant_kind, last_read_seq, joined_at, left_at
                  FROM conversation_participants
                 WHERE conversation_id = :conversation
                 ORDER BY joined_at, user_id
                SQL,
            ['conversation' => $conversationId],
        );

        return array_map(self::toParticipant(...), $rows);
    }

    public function addParticipant(string $conversationId, string $userId, string $participantKind): void
    {
        // The kind is read from the conversation rather than passed in, so
        // the denormalised column cannot disagree with the row it came from.
        // The composite foreign key would refuse a mismatch anyway; this
        // makes the refusal impossible to trigger by accident.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO conversation_participants
                    (conversation_id, user_id, conversation_kind, participant_kind)
                SELECT c.id, :user, c.kind, :participantKind
                  FROM conversations c
                 WHERE c.id = :conversation
                ON CONFLICT (conversation_id, user_id) DO UPDATE
                    SET left_at = NULL
                SQL,
            [
                'conversation' => $conversationId,
                'user' => $userId,
                'participantKind' => $participantKind,
            ],
        );
    }

    public function removeParticipant(string $conversationId, string $userId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversation_participants
                   SET left_at = now()
                 WHERE conversation_id = :conversation AND user_id = :user AND left_at IS NULL
                SQL,
            ['conversation' => $conversationId, 'user' => $userId],
        );
    }

    public function post(string $conversationId, ?string $authorId, string $authorKind, string $body): Message
    {
        return $this->connection->transactional(function () use (
            $conversationId,
            $authorId,
            $authorKind,
            $body,
        ): Message {
            // Serialises posting to this one thread. Two people typing at
            // once get consecutive numbers instead of one of them losing to
            // the unique index.
            $this->connection->fetchOne(
                'SELECT id FROM conversations WHERE id = :id FOR UPDATE',
                ['id' => $conversationId],
            );

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO messages (conversation_id, seq, author_user_id, author_kind, body)
                    SELECT :conversation,
                           coalesce(max(seq), 0) + 1,
                           :author,
                           :authorKind,
                           :body
                      FROM messages
                     WHERE conversation_id = :conversation
                    RETURNING id
                    SQL,
                [
                    'conversation' => $conversationId,
                    'author' => $authorId,
                    'authorKind' => $authorKind,
                    'body' => $body,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to post a message.');
            }

            // The thread's own timestamp moves, so a listing ordered by
            // recency shows what actually happened rather than when the
            // thread was opened.
            $this->connection->executeStatement(
                'UPDATE conversations SET updated_at = now() WHERE id = :id',
                ['id' => $conversationId],
            );

            $row = $this->connection->fetchAssociative(
                'SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages WHERE id = :id',
                ['id' => $id],
            );

            if ($row === false) {
                throw new RuntimeException('The message vanished during the transaction that wrote it.');
            }

            return self::toMessage($row);
        });
    }

    public function messages(string $conversationId, int $sinceSeq, int $limit): array
    {
        if (!Uuid::isValid($conversationId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::MESSAGE_COLUMNS . <<<'SQL'
                  FROM messages
                 WHERE conversation_id = :conversation AND seq > :since
                 ORDER BY seq
                 LIMIT :limit
                SQL,
            ['conversation' => $conversationId, 'since' => $sinceSeq, 'limit' => $limit],
            ['since' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(self::toMessage(...), $rows);
    }

    public function findMessage(string $conversationId, string $messageId): ?Message
    {
        if (!Uuid::isValid($conversationId) || !Uuid::isValid($messageId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::MESSAGE_COLUMNS . <<<'SQL'
                  FROM messages
                 WHERE id = :id AND conversation_id = :conversation
                SQL,
            ['id' => $messageId, 'conversation' => $conversationId],
        );

        return $row === false ? null : self::toMessage($row);
    }

    public function eraseMessage(string $messageId): void
    {
        // The body is emptied, not the row. §26 keeps invoices because the
        // law says so; a message carries no such obligation, so the text is
        // really gone and only the position in the thread remains.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE messages
                   SET body = '', deleted_at = coalesce(deleted_at, now())
                 WHERE id = :id
                SQL,
            ['id' => $messageId],
        );
    }

    public function markRead(string $conversationId, string $userId, int $seq): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversation_participants
                   SET last_read_seq = GREATEST(last_read_seq, :seq)
                 WHERE conversation_id = :conversation AND user_id = :user
                SQL,
            ['conversation' => $conversationId, 'user' => $userId, 'seq' => $seq],
            ['seq' => ParameterType::INTEGER],
        );
    }

    public function unreadCount(string $conversationId, string $userId): int
    {
        if (!Uuid::isValid($conversationId) || !Uuid::isValid($userId)) {
            return 0;
        }

        return $this->countOf(
            <<<'SQL'
                SELECT count(*)
                  FROM messages m
                  JOIN conversation_participants p
                    ON p.conversation_id = m.conversation_id AND p.user_id = :user
                 WHERE m.conversation_id = :conversation AND m.seq > p.last_read_seq
                SQL,
            ['conversation' => $conversationId, 'user' => $userId],
        );
    }

    public function close(string $conversationId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE conversations
                   SET status = 'CLOSED', closed_at = now(), updated_at = now()
                 WHERE id = :id AND status = 'OPEN'
                SQL,
            ['id' => $conversationId],
        );
    }

    private function insertParticipant(
        string $conversationId,
        string $kind,
        string $userId,
        string $participantKind,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO conversation_participants
                    (conversation_id, user_id, conversation_kind, participant_kind)
                VALUES (:conversation, :user, :kind, :participantKind)
                SQL,
            [
                'conversation' => $conversationId,
                'user' => $userId,
                'kind' => $kind,
                'participantKind' => $participantKind,
            ],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function countOf(string $sql, array $parameters): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toConversation(array $row): Conversation
    {
        return new Conversation(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'kind'),
            Row::string($row, 'subject'),
            Row::string($row, 'status'),
            Row::nullableString($row, 'created_by'),
            Row::nullableTimestamp($row, 'closed_at'),
            Row::timestamp($row, 'created_at'),
            Row::timestamp($row, 'updated_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toParticipant(array $row): Participant
    {
        return new Participant(
            Row::string($row, 'conversation_id'),
            Row::string($row, 'user_id'),
            Row::string($row, 'participant_kind'),
            Row::integer($row, 'last_read_seq'),
            Row::timestamp($row, 'joined_at'),
            Row::nullableTimestamp($row, 'left_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toMessage(array $row): Message
    {
        return new Message(
            Row::string($row, 'id'),
            Row::string($row, 'conversation_id'),
            Row::integer($row, 'seq'),
            Row::nullableString($row, 'author_user_id'),
            Row::string($row, 'author_kind'),
            Row::string($row, 'body'),
            Row::timestamp($row, 'created_at'),
            Row::nullableTimestamp($row, 'edited_at'),
            Row::nullableTimestamp($row, 'deleted_at'),
        );
    }
}
