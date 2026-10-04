<?php

declare(strict_types=1);

namespace App\Messaging\Service;

use App\Messaging\Domain\Conversation;
use App\Messaging\Domain\ConversationRepository;
use App\Messaging\Domain\Message;
use App\Messaging\Domain\ParticipantKind;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

/**
 * The platform's half of §12.3.
 *
 * A separate service from {@see Conversations}, not a mode of it. The two
 * differ in what they may reach — support crosses the tenant boundary, a
 * member never does — and merging them would put that difference inside an
 * `if`, which is the shape a cross-tenant leak takes.
 *
 * Only SUPPORT threads are reachable at all. The repository filters on kind
 * in SQL, so an INTERNAL conversation is not "refused" here — it is never
 * returned to be refused.
 */
final class SupportDesk
{
    public function __construct(
        private readonly ConversationRepository $conversations,
    ) {
    }

    /**
     * @return array{conversations: list<Conversation>, total: int, limit: int, offset: int}
     */
    public function list(StaffIdentity $staff, ?string $tenantId, int $limit, int $offset): array
    {
        $conversations = $this->conversations->listSupport($tenantId, $limit, $offset);

        return [
            'conversations' => $conversations,
            'total' => $this->conversations->countSupport($tenantId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * One thread. A conversation's `tenant_id` appears on the detail and not
     * on the list, so skimming the queue reveals no customer while opening a
     * thread reveals which company is asking and what about.
     */
    public function show(StaffIdentity $staff, string $conversationId): Conversation
    {
        return $this->require($staff, $conversationId, StaffPermission::SUPPORT_READ);
    }

    /**
     * @return array{messages: list<Message>, since_seq: int, limit: int}
     */
    public function messages(StaffIdentity $staff, string $conversationId, int $sinceSeq, int $limit): array
    {
        $conversation = $this->require($staff, $conversationId, StaffPermission::SUPPORT_READ);

        $messages = $this->conversations->messages($conversation->id, $sinceSeq, $limit);

        return ['messages' => $messages, 'since_seq' => $sinceSeq, 'limit' => $limit];
    }

    /**
     * Replies to a customer.
     *
     * Joining the thread is part of replying: the database will not accept a
     * message from somebody who is not a participant, and a support person
     * who has answered is, by definition, in the conversation. Joining as
     * STAFF also fails outright unless the thread is SUPPORT — the composite
     * key sees to that — so this cannot quietly become a way into an internal
     * thread.
     */
    public function post(StaffIdentity $staff, string $conversationId, string $body): Message
    {
        $conversation = $this->require($staff, $conversationId, StaffPermission::SUPPORT_RESPOND);

        if (!$conversation->isOpen()) {
            throw new ConflictException(
                'CONVERSATION_CLOSED',
                'That conversation has been closed.',
                ['status' => $conversation->status],
            );
        }

        $this->conversations->addParticipant($conversation->id, $staff->userId, ParticipantKind::STAFF);

        return $this->conversations->post(
            $conversation->id,
            $staff->userId,
            ParticipantKind::STAFF,
            $body,
        );
    }

    public function close(StaffIdentity $staff, string $conversationId): Conversation
    {
        $conversation = $this->require($staff, $conversationId, StaffPermission::SUPPORT_RESPOND);

        $this->conversations->close($conversation->id);

        $closed = $this->conversations->findSupport($conversation->id);

        if ($closed === null) {
            throw new NotFoundException('Conversation not found.', [], 'CONVERSATION_NOT_FOUND');
        }

        return $closed;
    }

    private function require(StaffIdentity $staff, string $conversationId, string $permission): Conversation
    {
        if (!$staff->can($permission)) {
            // Refused before the lookup, so a staff member without the
            // permission learns nothing about which conversations exist.
            // The controller checks this too; the duplication is deliberate,
            // because the service is what the next controller will call.
            throw ForbiddenException::permissionDenied($permission);
        }

        $conversation = $this->conversations->findSupport($conversationId);

        if ($conversation === null) {
            throw new NotFoundException('Conversation not found.', [], 'CONVERSATION_NOT_FOUND');
        }

        return $conversation;
    }
}
