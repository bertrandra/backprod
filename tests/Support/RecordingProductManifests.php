<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Product\Domain\ManifestAnswer;
use App\Product\Domain\ProductManifest;
use App\Product\Domain\ProductManifests;

/**
 * The product's own host, replaced: records what was asked of whom, and answers
 * with what the test last said.
 *
 * The parsing is the real one — a test says what the product *serves*, as the
 * bytes it would serve, and this runs them through {@see ProductManifest::parse()}.
 * A double that handed back an already-built manifest would let the console be
 * proved against a shape no product could actually send.
 */
final class RecordingProductManifests implements ProductManifests
{
    /** @var list<array{url: string, code: string}> */
    public array $asked = [];

    private ManifestAnswer $standing;

    public function __construct()
    {
        $this->standing = ManifestAnswer::failed(ManifestAnswer::NOT_SERVED);
    }

    /**
     * What the product serves, as a decoded body.
     *
     * @param array<string, mixed> $body
     */
    public function serves(array $body): void
    {
        $manifest = ProductManifest::parse($body);

        $this->standing = $manifest === null
            ? ManifestAnswer::failed(ManifestAnswer::NOT_A_MANIFEST)
            : ManifestAnswer::served($manifest);
    }

    public function fails(string $error): void
    {
        $this->standing = ManifestAnswer::failed($error);
    }

    public function of(string $appUrl, string $code): ManifestAnswer
    {
        $this->asked[] = ['url' => $appUrl, 'code' => $code];

        // The adapter's own check, kept here too: a test that configured a
        // manifest naming another product must see `WRONG_PRODUCT`, or the
        // guard would be proved only against curl.
        if ($this->standing->manifest !== null && !$this->standing->manifest->describes($code)) {
            return ManifestAnswer::failed(ManifestAnswer::WRONG_PRODUCT);
        }

        return $this->standing;
    }
}
