<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * The SDK's transport, replaced: records what the adapter sends and answers
 * with what the test queued. No network, no keys that work, nothing Stripe
 * ever sees — which is what makes `authorize()` and `refund()` unit-testable
 * for *what they send* rather than for what Stripe happened to answer.
 *
 * @phpstan-type Recorded array{method: string, url: string, headers: list<string>, params: array<mixed, mixed>}
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<Recorded> */
    public array $requests = [];

    /** @var list<array{0: string, 1: int}> body and status, consumed in order */
    private array $answers = [];

    /**
     * @param array<mixed, mixed> $body
     */
    public function answer(array $body, int $status = 200): void
    {
        $encoded = json_encode($body);
        \assert(\is_string($encoded));

        $this->answers[] = [$encoded, $status];
    }

    /**
     * @param 'delete'|'get'|'post'  $method
     * @param string                 $absUrl
     * @param array<mixed, mixed>    $headers
     * @param array<mixed, mixed>    $params
     * @param bool                   $hasFile
     * @param 'v1'|'v2'              $apiMode
     * @param null|int               $maxNetworkRetries
     *
     * @return array{0: string, 1: int, 2: array<mixed, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $lines = [];

        foreach ($headers as $header) {
            if (\is_string($header)) {
                $lines[] = $header;
            }
        }

        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $lines,
            'params' => $params,
        ];

        $next = array_shift($this->answers);

        if ($next === null) {
            throw new \LogicException('The test queued no Stripe answer for ' . $method . ' ' . $absUrl);
        }

        return [$next[0], $next[1], []];
    }

    /**
     * The value of one request header, or null. Headers arrive as full
     * `Name: value` strings, the way the SDK builds them.
     */
    public function header(int $request, string $name): ?string
    {
        foreach ($this->requests[$request]['headers'] ?? [] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, \strlen($name) + 1));
            }
        }

        return null;
    }
}
