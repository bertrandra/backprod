<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * One link in the chain a product has to complete before it can sell.
 *
 * The chain is a **dependency order, not a checklist**: a plan cannot be
 * written before a product exists, an offer cannot be written before a plan,
 * and nothing can be bought before an invoice can name its issuer. Presenting
 * those as an unordered list of boxes would let somebody start at the end and
 * discover the order through refusals — which is exactly how this platform's
 * own first installation went.
 *
 * **It carries facts, not sentences.** `key` names the step and `detail`
 * carries what was counted or found missing; the words belong to whatever
 * renders it, in whatever language that surface speaks. A step that shipped
 * its own English prose would make the API the wrong place to fix a typo.
 */
final class SetupStep
{
    /**
     * @param string               $key      a stable identifier the UI maps to words
     * @param bool                 $done     whether this link is complete
     * @param bool                 $blocking whether a sale is impossible until it is
     * @param array<string, mixed> $detail   what was counted, or what is missing
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $done,
        public readonly bool $blocking,
        public readonly array $detail = [],
    ) {
    }

    /**
     * A step that is blocking and not done — the reason a product cannot sell.
     */
    public function blocks(): bool
    {
        return $this->blocking && !$this->done;
    }
}
