<?php

declare(strict_types=1);

namespace App\Webhook\Infrastructure;

use App\Webhook\Domain\WebhookAnswer;
use App\Webhook\Domain\WebhookTransport;

/**
 * The wire, with curl.
 *
 * https only, no redirects, ten seconds: the signed body goes to the
 * address the operator typed and nowhere a 3xx points. The response body is
 * read and discarded — nothing the product says back is acted on, so nothing
 * it says back is stored.
 */
final class CurlWebhookTransport implements WebhookTransport
{
    public function post(string $url, string $body, array $headers): WebhookAnswer
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return new WebhookAnswer(null, 'UNREACHABLE');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_MAXFILESIZE => 65_536,
        ]);

        $answered = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($answered === false || $errno !== 0) {
            return new WebhookAnswer(null, match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'TIMEOUT',
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT => 'UNREACHABLE',
                CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE => 'TLS',
                CURLE_UNSUPPORTED_PROTOCOL => 'NOT_HTTPS',
                default => 'CURL_' . $errno,
            });
        }

        return new WebhookAnswer(is_int($status) ? $status : null, null);
    }
}
