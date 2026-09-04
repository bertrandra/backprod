<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\Channel;
use App\Notification\Domain\Notifier;

/**
 * The in-application channel (§27.1).
 *
 * The only one that does not leave the platform: the notification row *is*
 * the delivery, read back through `GET /notifications`. There is no provider,
 * nothing to fail outside the database, and nothing that can be refused for
 * want of an address.
 *
 * That makes it the floor. Whatever else is muted, unconsented or impossible,
 * a notice is still reachable — which is why the dispatcher always includes
 * it unless the recipient has explicitly muted the category on this channel.
 */
final class ScreenChannel implements Notifier
{
    public function channel(): string
    {
        return Channel::SCREEN;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $address, string $subject, string $body, array $payload): string
    {
        // Nothing to do: the row the recipient reads already exists. Returning
        // its own channel name as the "provider id" keeps the delivery record
        // uniform without inventing an identifier that names nothing.
        return Channel::SCREEN;
    }
}
