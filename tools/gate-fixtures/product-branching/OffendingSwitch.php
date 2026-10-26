<?php

declare(strict_types=1);

namespace App\GateFixture\ProductBranching;

/**
 * DELIBERATELY ILLEGAL: tools/prove-no-product-branching.php must report this
 * file, or the gate has stopped seeing the shape it is named after.
 */
final class OffendingSwitch
{
    public function f(string $productCode): int
    {
        switch ($productCode) {
            case 'plan':
                return 1;
        }

        return 0;
    }
}
