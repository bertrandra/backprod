<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
use App\Product\Domain\ProductKeys;
use App\Product\Service\ProductResolver;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Staff\Domain\StaffRepository;
use App\Tenant\Service\TenantResolver;
use App\User\Domain\UserDirectory;
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
 * How far it goes depends on the route's policy. Everything past
 * authentication is skipped for identity-only routes — product discovery
 * cannot require a product — but nothing skips authentication itself.
 *
 * A staff route (§12.2) leaves the chain after authentication and resolves a
 * platform role instead. That is a different question, asked of a different
 * repository, answered by a different context type: the two axes are never
 * resolved into one another here or anywhere else, and a user with no
 * platform role is refused whatever memberships they hold.
 *
 * Resource authorization — the final step — is deliberately not performed
 * here: it depends on the resource being addressed, so handlers ask the
 * resulting context. What this middleware guarantees is that no handler runs
 * without one.
 */
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthProvider $auth,
        private readonly UserDirectory $users,
        private readonly ProductResolver $products,
        private readonly TenantResolver $tenants,
        private readonly EntitlementRepository $entitlements,
        private readonly StaffRepository $staff,
        private readonly RoutePolicy $policy,
        private readonly ProductKeys $productKeys,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = $this->policy->for($request->getUri()->getPath());

        if ($policy === RoutePolicy::PUBLIC) {
            return $handler->handle($request);
        }

        if ($policy === RoutePolicy::PRODUCT) {
            // A product key, never a session: the bearer is recognised by
            // its prefix before any token verifier sees it, so a session on
            // a product route is nobody, and a key on a person's route is
            // nobody to the verifier. The product comes from the key —
            // no header is read (ADR-051 §4).
            return $handler->handle(
                $request->withAttribute(ProductContext::ATTRIBUTE, $this->productContext($this->bearerToken($request))),
            );
        }

        $identity = $this->auth->authenticate($this->bearerToken($request));

        // The provider says who they are; the directory says who that is
        // here, provisioning on first sight (ADR-017). Everything downstream
        // uses the internal id, never the provider subject.
        $tokenExpiresAt = $identity->expiresAt;
        $user = $this->users->resolve($identity);

        $request = $request->withAttribute(
            IdentityContext::ATTRIBUTE,
            new IdentityContext($user->id),
        );

        if ($policy === RoutePolicy::IDENTITY_ONLY) {
            return $handler->handle($request);
        }

        if ($policy === RoutePolicy::STAFF) {
            $identity = $this->staff->find($user->id);

            if ($identity === null) {
                // 403, not 404: they authenticated fine and this route
                // exists. Hiding it would also hide it from the staff who
                // need it, and the route names itself in the API catalogue
                // anyway.
                throw ForbiddenException::permissionDenied('staff');
            }

            return $handler->handle(
                $request->withAttribute(StaffContext::ATTRIBUTE, new StaffContext($identity)),
            );
        }

        $product = $this->products->resolve($request->getHeaderLine(ProductResolver::HEADER));

        $membership = $this->tenants->resolve(
            $user->id,
            $product->id,
            $request->getHeaderLine(TenantResolver::SELECTION_HEADER),
        );

        // Roles say who they are in the tenant; permissions say what that
        // allows; capabilities say what the tenant has bought. All three are
        // resolved before the handler runs, and they fail in different ways.
        $context = new RequestContext(
            $user->id,
            $product->id,
            $membership->tenantId,
            $membership->roles,
            $membership->permissions,
            // Named, so a seat this person holds counts and one held by a
            // colleague does not (§13.1). Resolving capabilities without the
            // person would make every seat tenant-wide.
            $this->entitlements->capabilitiesFor($membership->tenantId, $product->id, $user->id),
            $tokenExpiresAt,
            // Free: the directory read above already carries it, and a
            // catalogue answered in the reader's language would otherwise
            // ask for the same row again on every request.
            $user->locale,
        );

        return $handler->handle($request->withAttribute(RequestContext::ATTRIBUTE, $context));
    }

    private function productContext(string $bearer): ProductContext
    {
        $parts = explode('_', $bearer, 3);

        if (count($parts) !== 3 || $parts[0] !== 'bpk') {
            throw new UnauthenticatedException();
        }

        $key = $this->productKeys->authenticate($parts[1], $parts[2]);

        if ($key === null) {
            // An unknown id and a wrong secret are the same answer.
            throw new UnauthenticatedException();
        }

        // Recognised but not live: told which, because a rotated key and a
        // stolen one are diagnosed differently by whoever holds it.
        if ($key->isRevoked()) {
            throw new ForbiddenException('PRODUCT_KEY_REVOKED', 'This key was revoked.');
        }

        if ($key->isExpired(new \DateTimeImmutable())) {
            throw new ForbiddenException('PRODUCT_KEY_EXPIRED', 'This key has expired; issue a new one from the console.');
        }

        return new ProductContext($key);
    }

    private function bearerToken(ServerRequestInterface $request): string
    {
        // \S+ guarantees a non-empty capture, so a successful match needs no
        // further checking of the group.
        if (preg_match('/^Bearer[ ]+(?<token>\S+)$/i', $request->getHeaderLine('Authorization'), $matches) !== 1) {
            throw new UnauthenticatedException();
        }

        return $matches['token'];
    }
}
