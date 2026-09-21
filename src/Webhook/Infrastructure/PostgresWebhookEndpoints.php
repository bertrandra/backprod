<?php

declare(strict_types=1);

namespace App\Webhook\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\NotConfiguredException;
use App\Webhook\Domain\WebhookEndpoint;
use App\Webhook\Domain\WebhookEndpoints;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

/**
 * Webhook secrets, sealed (ADR-051 §5).
 *
 * A signing secret has to be readable, so it cannot be hashed the way a
 * product key is; it is sealed instead — XSalsa20-Poly1305, with a key
 * derived from the deployment's `WEBHOOK_SECRET_KEY` — so a copy of the
 * database is not a copy of every product's secret. The key lives in `.env`
 * with the other secrets (§31) and nowhere in this table.
 *
 * Rotation keeps the previous secret for a window: both sign, the product
 * swaps its copy when it can, and nothing is refused in between.
 */
final class PostgresWebhookEndpoints implements WebhookEndpoints
{
    public function __construct(
        private readonly Connection $connection,
        #[SensitiveParameter] private readonly string $key,
    ) {
    }

    public function issueSecret(string $productId): ?string
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $secret = 'bwh_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE products
                   SET webhook_previous_secret = webhook_secret,
                       webhook_previous_until = CASE WHEN webhook_secret IS NULL THEN NULL ELSE now() + make_interval(secs => :overlap) END,
                       webhook_secret = :sealed,
                       webhook_secret_issued_at = now(),
                       updated_at = now()
                 WHERE id = :id
                RETURNING id
                SQL,
            ['id' => $productId, 'sealed' => $this->seal($secret), 'overlap' => self::OVERLAP_SECONDS],
        );

        return $row === false ? null : $secret;
    }

    public function endpoint(string $productId): ?WebhookEndpoint
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT webhook_url, webhook_secret, webhook_previous_secret,
                       (webhook_previous_until IS NOT NULL AND webhook_previous_until > now()) AS previous_live
                  FROM products
                 WHERE id = :id AND active
                SQL,
            ['id' => $productId],
        );

        if ($row === false) {
            return null;
        }

        $url = Row::nullableString($row, 'webhook_url');
        $sealed = Row::nullableString($row, 'webhook_secret');

        if ($url === null || $sealed === null) {
            return null;
        }

        $secrets = [$this->unseal($sealed)];
        $previous = Row::nullableString($row, 'webhook_previous_secret');

        if ($previous !== null && Row::boolean($row, 'previous_live')) {
            $secrets[] = $this->unseal($previous);
        }

        return new WebhookEndpoint($productId, $url, $secrets);
    }

    private function seal(#[SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->derivedKey()));
    }

    private function unseal(string $sealed): string
    {
        $bytes = base64_decode($sealed, true);

        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new NotConfiguredException('WEBHOOKS_NOT_CONFIGURED', 'A sealed webhook secret could not be read. Issue a new one.');
        }

        $secret = sodium_crypto_secretbox_open(
            substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->derivedKey(),
        );

        if ($secret === false) {
            // A different key from the one that sealed it: the deployment's
            // WEBHOOK_SECRET_KEY changed. Nothing here can recover the secret;
            // issuing a new one is the only way forward, and this says so.
            throw new NotConfiguredException('WEBHOOKS_NOT_CONFIGURED', 'A sealed webhook secret does not open with this deployment’s WEBHOOK_SECRET_KEY. Issue a new one.');
        }

        return $secret;
    }

    private function derivedKey(): string
    {
        if (strlen($this->key) < 32) {
            throw new NotConfiguredException(
                'WEBHOOKS_NOT_CONFIGURED',
                'WEBHOOK_SECRET_KEY is not set (at least 32 characters), so no webhook secret can be sealed or read.',
            );
        }

        return sodium_crypto_generichash($this->key, 'backprod.webhook-secrets', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
