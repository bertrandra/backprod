<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * What came back from one attempt: a status when the product answered at
 * all, and otherwise the class of failure — `TIMEOUT`, `UNREACHABLE`, never
 * a message, because the message can quote the URL and this is stored where
 * the console reads it.
 */
final class WebhookAnswer
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $error,
    ) {
    }

    public function delivered(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
