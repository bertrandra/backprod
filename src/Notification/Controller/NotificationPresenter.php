<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Domain\Consent;
use App\Notification\Domain\Delivery;
use App\Notification\Domain\Notification;

/**
 * Notifications, as JSON.
 *
 * A delivery renders its suppression reason alongside its status, because
 * "SUPPRESSED" on its own tells a reader nothing they can act on, and the
 * reason is the whole point of recording it.
 *
 * `rendered_body` is deliberately not exposed on a delivery: it exists as
 * evidence of what was sent, and the recipient already has it — in their
 * inbox.
 */
final class NotificationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function notification(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' => $notification->category,
            'payload' => $notification->payload,
            'legal_effect' => $notification->legalEffect,
            'created_at' => $notification->createdAt->format(DATE_ATOM),
            'read_at' => $notification->readAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delivery(Delivery $delivery): array
    {
        return [
            'channel' => $delivery->channel,
            'status' => $delivery->status,
            'suppression_reason' => $delivery->suppressionReason,
            'attempts' => $delivery->attempts,
            'failure_reason' => $delivery->failureReason,
            'sent_at' => $delivery->sentAt?->format(DATE_ATOM),
            'delivered_at' => $delivery->deliveredAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function consent(Consent $consent): array
    {
        return [
            'id' => $consent->id,
            'channel' => $consent->channel,
            'purpose' => $consent->purpose,
            'granted_at' => $consent->grantedAt->format(DATE_ATOM),
            'revoked_at' => $consent->revokedAt?->format(DATE_ATOM),
            'source' => $consent->source,
            'live' => $consent->isLive(),
        ];
    }
}
