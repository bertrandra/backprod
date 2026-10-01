<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ManifestAnswer;
use App\Product\Domain\ProductManifest;
use App\Product\Domain\ProductManifests;
use JsonException;

/**
 * The wire, with curl.
 *
 * The posture is {@see \App\Webhook\Infrastructure\CurlWebhookTransport}'s,
 * deliberately: https only, no redirects, five seconds to connect and ten to
 * answer, and a hard cap on what will be read. The address comes from
 * `products.app_url`, which only staff can set — so this is not a caller
 * handing the platform a URL to fetch, it is the platform reading a file at an
 * address its own operator typed.
 *
 * The cap is 64 KiB because a manifest is three keys. A host that answers a
 * video is not a product, and reading it to find out would be the whole cost of
 * the mistake.
 */
final class CurlProductManifests implements ProductManifests
{
    private const MAX_BYTES = 65_536;

    public function of(string $appUrl, string $code): ManifestAnswer
    {
        $url = rtrim($appUrl, '/') . ProductManifest::PATH;
        $handle = curl_init($url);

        if ($handle === false) {
            return ManifestAnswer::failed(ManifestAnswer::UNREACHABLE);
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            // A server that ignores MAXFILESIZE because it sent no
            // Content-Length is stopped here instead: the callback refuses to
            // keep writing once the body is longer than a manifest can be.
            CURLOPT_BUFFERSIZE => 8_192,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (
                mixed $resource,
                int $expected,
                int $received,
            ): int => $received > self::MAX_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $errno !== 0) {
            return ManifestAnswer::failed(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => ManifestAnswer::TIMEOUT,
                CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE => ManifestAnswer::TLS,
                default => ManifestAnswer::UNREACHABLE,
            });
        }

        // Anything but a 200 is "this product does not serve one", which is the
        // ordinary state of most products and not a fault to be shouted about.
        // A 3xx lands here too: not followed, so not served.
        if (!is_int($status) || $status !== 200) {
            return ManifestAnswer::failed(ManifestAnswer::NOT_SERVED);
        }

        try {
            $decoded = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Overwhelmingly a login page or a single-page app's index.html
            // answering 200 for every path, which is what a product that has
            // not implemented the manifest actually does.
            return ManifestAnswer::failed(ManifestAnswer::NOT_A_MANIFEST);
        }

        $manifest = ProductManifest::parse($decoded);

        if ($manifest === null) {
            return ManifestAnswer::failed(ManifestAnswer::NOT_A_MANIFEST);
        }

        if (!$manifest->describes($code)) {
            // An `app_url` copied between two products, or a staging address
            // left in place. Reported rather than used: one product's versions
            // written onto another's configuration is silent and plausible.
            return ManifestAnswer::failed(ManifestAnswer::WRONG_PRODUCT);
        }

        return ManifestAnswer::served($manifest);
    }
}
