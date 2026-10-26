<?php

declare(strict_types=1);

namespace App\GateFixture\ProductBranching;

/**
 * DELIBERATELY ILLEGAL: tools/prove-no-product-branching.php must report this
 * file, or the gate has stopped seeing the shape it is named after.
 */
final class OffendingArrayKey
{
    /** @param array<string, string> $request */
    public function f(array $request): bool
    {
        return $request['product'] !== 'atlas';
    }
}
