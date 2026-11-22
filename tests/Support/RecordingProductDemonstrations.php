<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Product\Domain\DemonstrationSource;
use App\Product\Domain\ProductDemonstrations;

/**
 * A product's host answering for its demonstrations, without a network.
 *
 * It keeps what it was asked — the two addresses the platform composed —
 * because where the platform looked is as much the claim as what it found.
 */
final class RecordingProductDemonstrations implements ProductDemonstrations
{
    /** @var list<array{asked: string, embeds: string}> */
    public array $asked = [];

    /** @var list<array{number: int, name: string}> */
    private array $standing = [];

    /**
     * @param list<array{number: int, name: string}> $demonstrations
     */
    public function has(array $demonstrations): void
    {
        $this->standing = $demonstrations;
    }

    public function found(string $appUrl, DemonstrationSource $source): array
    {
        $this->asked[] = [
            'asked' => $source->sourceUrl($appUrl, 1),
            'embeds' => $source->embedUrl($appUrl, 1),
        ];

        return $this->standing;
    }
}
