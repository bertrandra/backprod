<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Messaging\Domain\Conversation;
use App\Messaging\Domain\Message;
use App\Messaging\Domain\Participant;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for conversations and messages.
 *
 * A deleted message keeps its position and reports `deleted: true` with an
 * empty body. Omitting it would renumber the thread from the client's point
 * of view; pretending it still says something would be a lie.
 */
final class MessagingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function conversation(Conversation $conversation, ?int $unread = null): array
    {
        $shape = [
            'id' => $conversation->id,
            'kind' => $conversation->kind,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'created_by' => $conversation->createdBy,
            'closed_at' => self::nullableMoment($conversation->closedAt),
            'created_at' => self::moment($conversation->createdAt),
            'updated_at' => self::moment($conversation->updatedAt),
        ];

        if ($unread !== null) {
            $shape['unread'] = $unread;
        }

        return $shape;
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'seq' => $message->seq,
            'author_user_id' => $message->authorUserId,
            'author_kind' => $message->authorKind,
            'body' => $message->body,
            'deleted' => $message->isDeleted(),
            'created_at' => self::moment($message->createdAt),
            'edited_at' => self::nullableMoment($message->editedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function participant(Participant $participant): array
    {
        return [
            'user_id' => $participant->userId,
            'kind' => $participant->participantKind,
            'last_read_seq' => $participant->lastReadSeq,
            'joined_at' => self::moment($participant->joinedAt),
            'left_at' => self::nullableMoment($participant->leftAt),
        ];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
