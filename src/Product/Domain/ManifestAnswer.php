<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What came back from asking one product about itself.
 *
 * Either a manifest or the class of failure — never both, and never a message.
 * The shape is {@see \App\Webhook\Domain\WebhookAnswer}'s and for the same
 * reason: a message can quote the URL, and this is rendered on a console screen
 * and written into a notification.
 *
 * An unreachable host is an answer. A product that serves nothing is not an
 * error condition of the platform — most products will never serve a manifest,
 * and a console that showed a red failure for each of them would teach its
 * operator to ignore the one that matters.
 */
final class ManifestAnswer
{
    public const UNREACHABLE = 'UNREACHABLE';
    public const TIMEOUT = 'TIMEOUT';
    public const TLS = 'TLS';
    public const NOT_SERVED = 'NOT_SERVED';
    public const NOT_A_MANIFEST = 'NOT_A_MANIFEST';

    /** The manifest names a different product than the one we asked about. */
    public const WRONG_PRODUCT = 'WRONG_PRODUCT';

    /** The product has no `app_url`, so there is nowhere to ask. */
    public const NO_ADDRESS = 'NO_ADDRESS';

    private function __construct(
        public readonly ?ProductManifest $manifest,
        public readonly ?string $error,
    ) {
    }

    public static function served(ProductManifest $manifest): self
    {
        return new self($manifest, null);
    }

    public static function failed(string $error): self
    {
        return new self(null, $error);
    }
}
