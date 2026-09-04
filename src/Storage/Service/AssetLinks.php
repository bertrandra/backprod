<?php

declare(strict_types=1);

namespace App\Storage\Service;

use App\Shared\Exceptions\ForbiddenException;
use App\Storage\Domain\Asset;
use DateTimeImmutable;

/**
 * Signed, expiring links to private assets (§31).
 *
 * Assets are not public, and the download route cannot require a bearer token
 * either: a browser fetching an image in an `<img>` tag sends no Authorization
 * header, and neither does a click on a download link. So the URL carries its
 * own proof.
 *
 * That makes this the same pattern as the payment webhook — a route reachable
 * without a session, which authenticates the *request* rather than the caller.
 * The differences are that the secret is ours rather than a provider's, and
 * that a link expires, so one that leaks into a log or a referrer header stops
 * working.
 *
 * What a link proves is narrow and worth stating: that whoever minted it could
 * see this asset at that moment. It says nothing about who is following it.
 * That is the trade a signed URL makes, and it is why the window is short and
 * why the signature covers the expiry — without that, an attacker could extend
 * one by editing the query string.
 */
final class AssetLinks
{
    public const DEFAULT_TTL_SECONDS = 300;
    public const MAX_TTL_SECONDS = 3600;

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @return array{url: string, expires_at: int}
     */
    public function mint(Asset $asset, int $ttlSeconds): array
    {
        $ttl = max(1, min($ttlSeconds, self::MAX_TTL_SECONDS));
        $expires = (new DateTimeImmutable())->getTimestamp() + $ttl;

        return [
            'url' => sprintf(
                '/api/v1/downloads/%s/content?expires=%d&signature=%s',
                $asset->id,
                $expires,
                $this->sign($asset->id, $expires),
            ),
            'expires_at' => $expires,
        ];
    }

    /**
     * Refuses anything that is not a live signature over this exact asset.
     */
    public function verify(string $assetId, string $expires, string $signature): void
    {
        if ($this->secret === '') {
            // Fail closed. With no secret configured nothing can be signed,
            // so nothing may be verified either — otherwise an empty key
            // would make every link valid.
            throw ForbiddenException::permissionDenied('assets.link');
        }

        if (preg_match('/^\d{1,20}$/', $expires) !== 1) {
            throw ForbiddenException::permissionDenied('assets.link');
        }

        $expiresAt = (int) $expires;

        if ($expiresAt < (new DateTimeImmutable())->getTimestamp()) {
            // The clock decides, as everywhere else. A link that has lapsed
            // is refused whether or not anything swept it.
            throw ForbiddenException::permissionDenied('assets.link');
        }

        // Constant-time, so the comparison does not leak the correct
        // signature one byte at a time.
        if (!hash_equals($this->sign($assetId, $expiresAt), $signature)) {
            throw ForbiddenException::permissionDenied('assets.link');
        }
    }

    private function sign(string $assetId, int $expires): string
    {
        // The expiry is inside the signed material. Signing only the id would
        // let anyone move the deadline by editing the query string.
        return hash_hmac('sha256', $assetId . "\n" . $expires, $this->secret);
    }
}
