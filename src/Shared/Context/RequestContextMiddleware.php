<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Product\Service\ProductResolver;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Tenant\Service\TenantResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the request context in the order Architecture V2 §10.6 requires:
 *
 *   authentication → product → tenant → roles → entitlements → authorization
 *
 * The order is the security boundary, so it is written once, here, rather
 * than assembled per route where a step could be omitted. Each stage is
 * delegated to a collaborator that is testable on its own; this class owns
 * only the sequence and the mapping from HTTP to those collaborators.
 *
 * Resource authorization — the final step — is deliberately not performed
 * here: it depends on the resource being addressed, so handlers ask the
 * resulting RequestContext. What this middleware guarantees is that no
 * handler runs without one.
 */
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthProvider $auth,
        private readonly ProductResolver $products,
        private readonly TenantResolver $tenants,
        private readonly EntitlementRepository $entitlements,
        private readonly PublicRoutes $publicRoutes,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->publicRoutes->includes($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $identity = $this->auth->authenticate($this->bearerToken($request));

        $product = $this->products->resolve($request->getHeaderLine(ProductResolver::HEADER));

        $membership = $this->tenants->resolve(
            $identity->userId,
            $product->id,
            $request->getHeaderLine(TenantResolver::SELECTION_HEADER),
        );

        $context = new RequestContext(
            $identity->userId,
            $product->id,
            $membership->tenantId,
            $membership->roles,
            $this->entitlements->capabilitiesFor($membership->tenantId, $product->id),
        );

        return $handler->handle($request->withAttribute(RequestContext::ATTRIBUTE, $context));
    }

    private function bearerToken(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer[ ]+(?<token>\S+)$/i', $header, $matches) !== 1) {
            throw new UnauthenticatedException();
        }

        $token = $matches['token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new UnauthenticatedException();
        }

        return $token;
    }
}
