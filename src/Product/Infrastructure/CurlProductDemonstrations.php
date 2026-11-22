<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\DemonstrationSource;
use App\Product\Domain\ProductDemonstrations;

/**
 * Asks a product for each of its demonstrations, by number.
 *
 * **All at once, and only the first bytes of each.** Twenty requests one
 * after the other would be twenty round trips before a list opened, so they
 * go together. And a demonstration is a whole project — the ones this was
 * written against weigh between 180 KB and 1.9 MB — of which the list wants
 * one line, its name, which a product writes first. So each transfer is
 * stopped once it has given {@see self::ENOUGH} bytes: the status says the
 * number exists, and the opening says what it is called.
 *
 * The same refusals as {@see CurlProductManifests}: HTTPS only, no
 * redirect — a product's host must not be able to send the platform
 * somewhere else — and a deadline, because this runs inside a request.
 */
final class CurlProductDemonstrations implements ProductDemonstrations
{
    private const ENOUGH = 4_096;

    private const TIMEOUT_SECONDS = 8;

    public function found(string $appUrl, DemonstrationSource $source): array
    {
        $multi = curl_multi_init();
        $handles = [];
        /** @var array<int, string> $openings */
        $openings = [];

        for ($number = 1; $number <= DemonstrationSource::UP_TO; ++$number) {
            $handle = curl_init($source->sourceUrl($appUrl, $number));

            if ($handle === false) {
                continue;
            }

            $openings[$number] = '';

            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                // Asked for politely as well as enforced below: a server
                // that honours a range sends 4 KB instead of 2 MB.
                CURLOPT_RANGE => '0-' . (self::ENOUGH - 1),
                CURLOPT_WRITEFUNCTION => static function (mixed $resource, string $chunk) use (&$openings, $number): int {
                    $openings[$number] .= $chunk;

                    // Returning less than was given stops the transfer,
                    // which is the point: the rest is a project nobody asked for.
                    return strlen($openings[$number]) >= self::ENOUGH ? 0 : strlen($chunk);
                },
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$number] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $found = [];

        foreach ($handles as $number => $handle) {
            $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            // 206 where the range was honoured, 200 where it was not.
            if ($code !== 200 && $code !== 206) {
                continue;
            }

            $name = self::nameIn($openings[$number]);

            if ($name !== null) {
                $found[] = ['number' => $number, 'name' => $name];
            }
        }

        curl_multi_close($multi);

        return $found;
    }

    /**
     * The first `"name"` of a JSON document cut short, or null where what
     * answered is not one.
     *
     * A pattern and not a parser, because the document is deliberately
     * incomplete. It must open as an object — a single-page application
     * answering its index for every path says 200 to anything, and that is
     * not a demonstration.
     */
    private static function nameIn(string $opening): ?string
    {
        if (!str_starts_with(ltrim($opening), '{')) {
            return null;
        }

        if (preg_match('/"name"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/u', $opening, $matches) !== 1) {
            return null;
        }

        $name = json_decode($matches[1]);

        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return mb_substr(trim($name), 0, 200);
    }
}
