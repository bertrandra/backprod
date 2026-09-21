<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * The wire, behind a port like every other external concern: what the
 * delivery job needs is "post these bytes with these headers and say what
 * came back", and a test needs to say what comes back without a server.
 *
 * The adapter never follows a redirect (a 3xx is an answer, and following
 * it would send a signed body somewhere the operator did not name) and
 * never throws: an unreachable host is an answer too.
 */
interface WebhookTransport
{
    /** How long the product has to answer, per ADR-051 §5. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers): WebhookAnswer;
}
