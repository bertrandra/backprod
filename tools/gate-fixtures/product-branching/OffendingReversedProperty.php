<?php

declare(strict_types=1);

namespace App\GateFixture\ProductBranching;

/**
 * DELIBERATELY ILLEGAL: tools/prove-no-product-branching.php must report this
 * file, or the gate has stopped seeing the shape it is named after.
 */
final class OffendingReversedProperty
{
    public function f(object $context): bool
    {
        return 'atlas' == $context->product->code;
    }
}
