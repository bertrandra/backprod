<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

/**
 * Where conversations live.
 *
 * Two families of read, deliberately not one. The tenant-facing methods take
 * a tenant and product and scope every query to them; the staff-facing ones
 * take a conversation without a tenant, because platform staff have no
 * membership to derive one from (§12.2).
 *
 * They are separate methods rather than one with a nullable tenant for the
 * reason §12.2 keeps the tables apart: a single method whose scoping depends
 * on an argument being null is one forgotten argument away from serving a
 * tenant's thread to the wrong tenant. Separate, the mistake does not compile
 * into anything dangerous — it just fails to find a row.
 */
interface ConversationRepository
{
    /**
     * Threads this user takes part in, within their tenant and product.
     *
     * Scoped by participation as well as by tenant: being a member of a
     * tenant does not make its every conversation yours to read.
     *
     * @return list<Conversation>
     */
    public function listForParticipant(
        string $tenantId,
        string $productId,
        string $userId,
        int $limit,
        int $offset,
    ): array;

    public function countForParticipant(string $tenantId, string $productId, string $userId): int;

    public function find(string $tenantId, string $productId, string $conversationId): ?Conversation;

    /**
     * A support thread, addressed without a tenant.
     *
     * Returns SUPPORT conversations only. An INTERNAL one is not staff's to
     * read at all, and the repository refuses rather than leaving that to a
     * caller's `if`.
     */
    public function findSupport(string $conversationId): ?Conversation;

    /**
     * @return list<Conversation>
     */
    public function listSupport(?string $tenantId, int $limit, int $offset): array;

    public function countSupport(?string $tenantId): int;

    /**
     * Opens a thread with its first participants.
     *
     * @param list<string> $memberIds users to add besides the creator
     */
    public function start(
        string $tenantId,
        string $productId,
        string $kind,
        string $subject,
        string $creatorId,
        array $memberIds,
    ): Conversation;

    public function participant(string $conversationId, string $userId): ?Participant;

    /**
     * @return list<Participant>
     */
    public function participants(string $conversationId): array;

    public function addParticipant(string $conversationId, string $userId, string $participantKind): void;

    /**
     * Marks a participant as having left. Never deletes the row: their
     * messages keep their author.
     */
    public function removeParticipant(string $conversationId, string $userId): void;

    public function post(string $conversationId, ?string $authorId, string $authorKind, string $body): Message;

    /**
     * Messages after `sinceSeq`, oldest first — the polling read.
     *
     * @return list<Message>
     */
    public function messages(string $conversationId, int $sinceSeq, int $limit): array;

    public function findMessage(string $conversationId, string $messageId): ?Message;

    /**
     * Erases the body and leaves the row as a tombstone.
     */
    public function eraseMessage(string $messageId): void;

    /**
     * Moves the watermark forward. Never backwards, whatever is passed.
     */
    public function markRead(string $conversationId, string $userId, int $seq): void;

    public function unreadCount(string $conversationId, string $userId): int;

    public function close(string $conversationId): void;
}
