<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use Psr\Http\Message\ServerRequestInterface;

final class OfferRoute
{
    public static function id(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('offerId');

        return is_string($value) ? $value : '';
    }
}
