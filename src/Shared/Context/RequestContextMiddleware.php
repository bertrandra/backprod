<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Auth\Domain\AuthProvider;
use App\Entitlement\Domain\EntitlementRepository;
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
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = $this->policy->for($request->getUri()->getPath());

        if ($policy === RoutePolicy::PUBLIC) {
            return $handler->handle($request);
        }

        $identity = $this->auth->authenticate($this->bearerToken($request));

        // The provider says who they are; the directory says who that is
        // here, provisioning on first sight (ADR-017). Everything downstream
        // uses the internal id, never the provider subject.
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
            $this->entitlements->capabilitiesFor($membership->tenantId, $product->id),
        );

        return $handler->handle($request->withAttribute(RequestContext::ATTRIBUTE, $context));
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
