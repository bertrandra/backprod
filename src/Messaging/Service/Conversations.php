<?php

declare(strict_types=1);

namespace App\Messaging\Service;

use App\Messaging\Domain\Conversation;
use App\Messaging\Domain\ConversationKind;
use App\Messaging\Domain\ConversationRepository;
use App\Messaging\Domain\Message;
use App\Messaging\Domain\Participant;
use App\Messaging\Domain\ParticipantKind;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tenant\Domain\TenantMemberRepository;

/**
 * The tenant's half of §12.3.
 *
 * Two rules govern every method, and they are separate on purpose:
 *
 *   1. the conversation must belong to the caller's resolved tenant *and*
 *      product — never an id taken at face value;
 *   2. the caller must be a participant of it.
 *
 * Being a member of a tenant does not make its every thread yours to read.
 * Failing either rule answers the same 404, so a member cannot probe for the
 * existence of conversations they are not in: "no such conversation" and "not
 * yours" are deliberately indistinguishable.
 */
final class Conversations
{
    public const MAX_SUBJECT = 200;
    public const MAX_BODY = 20_000;

    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly TenantMemberRepository $members,
    ) {
    }

    /**
     * @return array{conversations: list<Conversation>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, string $userId, int $limit, int $offset): array
    {
        return [
            'conversations' => $this->conversations->listForParticipant(
                $tenantId,
                $productId,
                $userId,
                $limit,
                $offset,
            ),
            'total' => $this->conversations->countForParticipant($tenantId, $productId, $userId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $userId, string $conversationId): Conversation
    {
        return $this->mine($tenantId, $productId, $userId, $conversationId);
    }

    /**
     * @return list<Participant>
     */
    public function participantsOf(Conversation $conversation): array
    {
        return $this->conversations->participants($conversation->id);
    }

    public function unreadFor(Conversation $conversation, string $userId): int
    {
        return $this->conversations->unreadCount($conversation->id, $userId);
    }

    /**
     * Opens a thread.
     *
     * A tenant may only open INTERNAL conversations here. A SUPPORT thread is
     * opened the same way — a customer asking the platform something — but
     * every participant added must still be a member of this tenant, so the
     * kind changes who may later join, not who may start it.
     *
     * @param list<string> $memberIds
     */
    public function start(
        string $tenantId,
        string $productId,
        string $userId,
        string $kind,
        string $subject,
        array $memberIds,
    ): Conversation {
        if (!ConversationKind::isKnown($kind)) {
            throw new ConflictException(
                'CONVERSATION_KIND_UNKNOWN',
                'A conversation is either internal or a support thread.',
                ['kind' => $kind],
            );
        }

        foreach ($memberIds as $memberId) {
            $this->assertMemberOfThisTenant($tenantId, $productId, $memberId);
        }

        return $this->conversations->start(
            $tenantId,
            $productId,
            $kind,
            $subject,
            $userId,
            $memberIds,
        );
    }

    public function post(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        string $body,
    ): Message {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        if (!$conversation->isOpen()) {
            throw new ConflictException(
                'CONVERSATION_CLOSED',
                'That conversation has been closed.',
                ['status' => $conversation->status],
            );
        }

        // MEMBER, always. A tenant user writes as themselves; the STAFF kind
        // belongs to the other surface, and the database refuses it here in
        // any case because no STAFF participant row exists for them.
        return $this->conversations->post($conversation->id, $userId, ParticipantKind::MEMBER, $body);
    }

    /**
     * @return array{messages: list<Message>, since_seq: int, limit: int}
     */
    public function messages(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        int $sinceSeq,
        int $limit,
    ): array {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        return [
            'messages' => $this->conversations->messages($conversation->id, $sinceSeq, $limit),
            'since_seq' => $sinceSeq,
            'limit' => $limit,
        ];
    }

    public function markRead(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        int $seq,
    ): Participant {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        $this->conversations->markRead($conversation->id, $userId, $seq);

        $participant = $this->conversations->participant($conversation->id, $userId);

        if ($participant === null) {
            throw new NotFoundException('Conversation not found.', [], 'CONVERSATION_NOT_FOUND');
        }

        return $participant;
    }

    /**
     * Adds somebody to a thread.
     *
     * The guard that matters: the person added must already be a member of
     * *this* tenant and product. Without it, a conversation would be a way to
     * hand a stranger a view of a tenant's data — the plainest form of the
     * cross-tenant leak this whole milestone is shaped to prevent.
     */
    public function addParticipant(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        string $addedUserId,
    ): void {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        $this->assertMemberOfThisTenant($tenantId, $productId, $addedUserId);

        $this->conversations->addParticipant($conversation->id, $addedUserId, ParticipantKind::MEMBER);
    }

    public function removeParticipant(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        string $removedUserId,
    ): void {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        $participant = $this->conversations->participant($conversation->id, $removedUserId);

        if ($participant === null) {
            throw new NotFoundException('That person is not in this conversation.', [], 'PARTICIPANT_NOT_FOUND');
        }

        if ($participant->isStaff()) {
            // A tenant cannot eject the platform from its own support thread;
            // closing it is the way to end the conversation.
            throw new ConflictException(
                'PARTICIPANT_NOT_REMOVABLE',
                'Close the conversation instead of removing support from it.',
            );
        }

        $this->conversations->removeParticipant($conversation->id, $removedUserId);
    }

    public function close(string $tenantId, string $productId, string $userId, string $conversationId): Conversation
    {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        $this->conversations->close($conversation->id);

        return $this->mine($tenantId, $productId, $userId, $conversationId);
    }

    /**
     * Erases a message's text, keeping its place in the thread.
     *
     * Only its author. A thread's history is not an administrator's to edit,
     * and "delete anything in my tenant" is a power this platform has no
     * reason to grant for chat.
     */
    public function deleteMessage(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
        string $messageId,
    ): void {
        $conversation = $this->mine($tenantId, $productId, $userId, $conversationId);

        $message = $this->conversations->findMessage($conversation->id, $messageId);

        if ($message === null) {
            throw new NotFoundException('Message not found.', [], 'MESSAGE_NOT_FOUND');
        }

        if ($message->authorUserId !== $userId) {
            throw new ConflictException(
                'MESSAGE_NOT_DELETABLE',
                'Only the author of a message may delete it.',
            );
        }

        $this->conversations->eraseMessage($message->id);
    }

    /**
     * The conversation, if it is in this tenant and product and the caller is
     * in it. Both failures answer the same way on purpose.
     */
    private function mine(
        string $tenantId,
        string $productId,
        string $userId,
        string $conversationId,
    ): Conversation {
        $conversation = $this->conversations->find($tenantId, $productId, $conversationId);

        if ($conversation === null || $this->conversations->participant($conversationId, $userId) === null) {
            throw new NotFoundException('Conversation not found.', [], 'CONVERSATION_NOT_FOUND');
        }

        return $conversation;
    }

    private function assertMemberOfThisTenant(string $tenantId, string $productId, string $userId): void
    {
        if ($this->members->findMember($tenantId, $productId, $userId) === null) {
            // Deliberately not "no such user": whether a user id exists
            // elsewhere on the platform is not this tenant's business.
            throw new NotFoundException(
                'That person is not a member of this tenant.',
                [],
                'MEMBER_NOT_FOUND',
            );
        }
    }
}
