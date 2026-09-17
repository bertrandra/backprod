<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Service\Profile;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/me — update the caller's own profile.
 *
 * Only display_name and the default product are writable. Email and identity
 * belong to the provider (ADR-014); accepting them here would let the local
 * record disagree with the token that authenticated the caller. The default
 * product is a code the person holds, checked by {@see Profile}; null clears
 * it and the shell then chooses among what they have.
 */
final class UpdateMeController implements RouteHandler
{
    public function __construct(private readonly Profile $profile)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('account.manage');

        $body = JsonBody::of($request);

        // PATCH is partial: an absent field is untouched, whereas an explicit
        // null clears the name. Those are different requests and must not be
        // collapsed into one.
        $user = $body->has('display_name')
            ? $this->profile->rename($context->userId, $body->optionalNullableString('display_name', 120))
            : $this->profile->of($context->userId);

        if ($body->has('default_product')) {
            $user = $this->profile->chooseDefaultProduct(
                $context->userId,
                $body->optionalNullableString('default_product', 64),
            );
        }

        return new JsonResponse([
            'user_id' => $context->userId,
            'email' => $user?->email,
            'display_name' => $user?->displayName,
            'default_product' => $this->profile->defaultProductCode($context->userId),
        ], 200);
    }
}
