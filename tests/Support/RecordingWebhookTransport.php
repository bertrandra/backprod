<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Webhook\Domain\WebhookAnswer;
use App\Webhook\Domain\WebhookTransport;

/**
 * The wire, replaced: records what the delivery job sends and answers with
 * what the test last said — a status, or a failure class — so the job is
 * tested for what it sends and what it does with the answer, not for
 * whether a server happened to be up.
 *
 * @phpstan-type Sent array{url: string, body: string, headers: array<string, string>}
 */
final class RecordingWebhookTransport implements WebhookTransport
{
    /** @var list<Sent> */
    public array $sent = [];

    /** What every post is answered with until the test says otherwise. */
    private WebhookAnswer $standing;

    public function __construct()
    {
        $this->standing = new WebhookAnswer(200, null);
    }

    public function answer(?int $status, ?string $error = null): void
    {
        $this->standing = new WebhookAnswer($status, $error);
    }

    public function post(string $url, string $body, array $headers): WebhookAnswer
    {
        $this->sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->standing;
    }
}
