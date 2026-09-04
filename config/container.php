<?php

declare(strict_types=1);

use App\Auth\Domain\AuthProvider;
use App\Auth\Infrastructure\NullSigningKeySource;
use App\Auth\Infrastructure\SigningKeySource;
use App\Auth\Infrastructure\StaticSigningKeySource;
use App\Auth\Infrastructure\SupabaseJwtAuthProvider;
use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\InvoicePaid;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Infrastructure\PostgresBillingProfileRepository;
use App\Billing\Infrastructure\PostgresCreditNoteRepository;
use App\Billing\Infrastructure\PostgresInvoiceRepository;
use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\EarlyTerminationCharge;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Infrastructure\PostgresCatalogueRepository;
use App\Commerce\Infrastructure\PostgresEntitlementRepository;
use App\Commerce\Infrastructure\PostgresSubscriptionRepository;
use App\EInvoice\Domain\TransmissionEffect;
use App\EInvoice\Domain\TransmissionRepository;
use App\EInvoice\Infrastructure\PostgresTransmissionRepository;
use App\EInvoice\Infrastructure\StubEInvoiceProvider;
use App\EInvoice\Service\EInvoiceProviders;
use App\EInvoice\Service\InvoiceTransmissionEffect;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Domain\UsageMeter;
use App\Job\Domain\JobRepository;
use App\Job\Infrastructure\PostgresJobRepository;
use App\Job\Service\ExpireQuotes;
use App\Job\Service\ExpireSubscriptions;
use App\Job\Service\JobHandlers;
use App\Messaging\Domain\ConversationRepository;
use App\Messaging\Infrastructure\PostgresConversationRepository;
use App\Notification\Domain\Channel;
use App\Notification\Domain\NotificationRepository;
use App\Notification\Infrastructure\LogNotifier;
use App\Notification\Infrastructure\PostgresNotificationRepository;
use App\Notification\Infrastructure\ScreenChannel;
use App\Notification\Service\DispatchNotifications;
use App\Notification\Service\Notifications;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentSettlement;
use App\Payment\Infrastructure\PostgresPaymentRepository;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Payment\Service\InvoiceSettlement;
use App\Payment\Service\PaymentProviders;
use App\Product\Domain\ProductRegistry;
use App\Product\Domain\ProductRepository;
use App\Product\Infrastructure\PostgresProductRegistry;
use App\Product\Infrastructure\PostgresProductRepository;
use App\Project\Domain\ProjectRepository;
use App\Project\Infrastructure\PostgresProjectRepository;
use App\Project\Infrastructure\ProjectUsageSource;
use App\Project\Service\ExportProject;
use App\Project\Service\ProjectWorkspace;
use App\Sales\Domain\OrderFulfilment;
use App\Sales\Domain\SalesRepository;
use App\Sales\Infrastructure\PostgresSalesRepository;
use App\Sales\Service\ChargeOnEarlyTermination;
use App\Sales\Service\CompleteOrderOnPayment;
use App\Sales\Service\InvoiceThenSubscribe;
use App\Shared\Context\RequestContextMiddleware;
use App\Shared\Context\RoutePolicy;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Http\Middleware\ErrorHandlerMiddleware;
use App\Shared\Http\Middleware\RequestIdMiddleware;
use App\Shared\Http\MiddlewarePipeline;
use App\Shared\Http\Router;
use App\Shared\Logging\ErrorLogLogger;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffRepository;
use App\Staff\Domain\TenantDirectory;
use App\Staff\Infrastructure\PostgresStaffAccessLog;
use App\Staff\Infrastructure\PostgresStaffRepository;
use App\Staff\Infrastructure\PostgresTenantDirectory;
use App\Storage\Domain\AssetRepository;
use App\Storage\Domain\StorageProvider;
use App\Storage\Infrastructure\LocalStorageProvider;
use App\Storage\Infrastructure\PostgresAssetRepository;
use App\Storage\Service\AssetLinks;
use App\Tax\Domain\TaxRepository;
use App\Tax\Domain\VatNumberValidator;
use App\Tax\Infrastructure\PostgresTaxRepository;
use App\Tax\Infrastructure\StubVatNumberValidator;
use App\Tenant\Domain\TenantMemberRepository;
use App\Tenant\Domain\TenantMembershipRepository;
use App\Tenant\Domain\TenantRepository;
use App\Tenant\Infrastructure\MemberUsageSource;
use App\Tenant\Infrastructure\PostgresTenantMemberRepository;
use App\Tenant\Infrastructure\PostgresTenantMembershipRepository;
use App\Tenant\Infrastructure\PostgresTenantRepository;
use App\User\Domain\UserDirectory;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\PostgresUserDirectory;
use App\User\Infrastructure\PostgresUserRepository;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\create;
use function DI\factory;
use function DI\get;
use function FastRoute\simpleDispatcher;

/**
 * Container definitions.
 *
 * Middleware order is the security boundary and is declared here, once:
 * RequestId first so every failure downstream carries a correlation id, then
 * the error handler, then the §10.6 context chain, then routing. A route
 * cannot opt out of the chain — only the public-routes list can exempt a
 * path, and it is deliberately tiny.
 *
 * @param array<string, mixed> $overrides definitions replacing the defaults,
 *                                        used by tests to supply doubles
 */
return static function (array $overrides = []): ContainerInterface {
    // Reads $_ENV first so a .env loaded immutably is visible, then falls back
    // to the real environment for container and CI deployments.
    $env = static function (string $key, string $default = ''): string {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    };

    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        LoggerInterface::class => autowire(ErrorLogLogger::class),

        // --- Identity -------------------------------------------------------
        // Without configured keys this yields an empty key set, so every token
        // fails and the API authenticates nobody (see NullSigningKeySource).
        SigningKeySource::class => factory(static function () use ($env): SigningKeySource {
            $jwks = $env('SUPABASE_JWKS');

            return $jwks === '' ? new NullSigningKeySource() : StaticSigningKeySource::fromJson($jwks);
        }),

        AuthProvider::class => autowire(SupabaseJwtAuthProvider::class)
            ->constructorParameter('keys', get(SigningKeySource::class))
            ->constructorParameter('expectedIssuer', $env('SUPABASE_ISSUER'))
            ->constructorParameter('expectedAudience', $env('SUPABASE_AUDIENCE', 'authenticated')),

        // --- Persistence ----------------------------------------------------
        // DBAL connects lazily, so an unconfigured or unreachable database
        // does not stop the process from serving the liveness probe.
        Connection::class => factory(
            static fn (): Connection => ConnectionFactory::fromDsn($env('DATABASE_DSN')),
        ),

        // --- Platform data --------------------------------------------------
        UserDirectory::class => autowire(PostgresUserDirectory::class),
        UserRepository::class => autowire(PostgresUserRepository::class),
        ProductRepository::class => autowire(PostgresProductRepository::class),
        ProductRegistry::class => autowire(PostgresProductRegistry::class),
        CatalogueRepository::class => autowire(PostgresCatalogueRepository::class),
        SubscriptionRepository::class => autowire(PostgresSubscriptionRepository::class),
        BillingProfileRepository::class => autowire(PostgresBillingProfileRepository::class),
        InvoiceRepository::class => autowire(PostgresInvoiceRepository::class),
        CreditNoteRepository::class => autowire(PostgresCreditNoteRepository::class),
        PaymentRepository::class => autowire(PostgresPaymentRepository::class),
        PaymentSettlement::class => autowire(InvoiceSettlement::class),
        SalesRepository::class => autowire(PostgresSalesRepository::class),
        OrderFulfilment::class => autowire(InvoiceThenSubscribe::class),

        // Leaving a commitment early is a sale like any other: it produces a
        // numbered, taxed document. Bound here rather than called directly so
        // subscriptions never learn how an invoice is made.
        EarlyTerminationCharge::class => autowire(ChargeOnEarlyTermination::class),

        // The far side of the payment gate. Both ways an invoice can reach
        // PAID — a provider's webhook, an operator reconciling a transfer —
        // fire this, so a sale is released by the money arriving rather than
        // by which route it arrived through.
        InvoicePaid::class => autowire(CompleteOrderOnPayment::class),
        TransmissionRepository::class => autowire(PostgresTransmissionRepository::class),
        TransmissionEffect::class => autowire(InvoiceTransmissionEffect::class),

        // --- Payment providers ----------------------------------------------
        // A registry, not one provider: non-negotiable #17 is about not
        // coupling to a single PSP, and a platform migrating between two runs
        // both while payments started with the old one are still settling.
        //
        // Without a configured secret the stub is left out entirely, so a
        // deployment that has not been given a provider cannot take money —
        // rather than taking it through something whose signatures anyone
        // could forge. That is the same fail-closed shape as the JWKS above.
        PaymentProviders::class => factory(static function () use ($env): PaymentProviders {
            $secret = $env('STUB_PAYMENT_SIGNING_SECRET');

            return new PaymentProviders($secret === '' ? [] : [new StubPaymentProvider($secret)]);
        }),

        // --- E-invoicing platforms (§25.1) -----------------------------------
        // Same shape and the same fail-closed rule: with no configured secret
        // there is no platform, and nothing can be transmitted. A transmission
        // record produced by an unverifiable adapter would be worse than none,
        // because it could be mistaken for evidence of compliance.
        EInvoiceProviders::class => factory(static function () use ($env): EInvoiceProviders {
            $secret = $env('STUB_EINVOICE_SIGNING_SECRET');

            return new EInvoiceProviders($secret === '' ? [] : [new StubEInvoiceProvider($secret)]);
        }),
        ProjectRepository::class => autowire(PostgresProjectRepository::class),
        TenantRepository::class => autowire(PostgresTenantRepository::class),
        TenantMemberRepository::class => autowire(PostgresTenantMemberRepository::class),
        TenantMembershipRepository::class => autowire(PostgresTenantMembershipRepository::class),

        // Entitlements now come from what the tenant actually subscribed to.
        // A tenant with no subscription has no rows, and therefore no
        // capabilities — absence still reads as "may use nothing".
        EntitlementRepository::class => autowire(PostgresEntitlementRepository::class),

        // Which quotas can be measured, and by what. Adding a quota is a
        // line here plus a UsageSource in the module that owns the thing
        // being counted — never a branch in the entitlement code.
        //
        // A quota with no source is reported as unmetered rather than
        // enforced against a number nobody produced.
        UsageMeter::class => create(UsageMeter::class)->constructor([
            ProjectWorkspace::QUOTA => get(ProjectUsageSource::class),
            MemberUsageSource::QUOTA => get(MemberUsageSource::class),
        ]),

        // --- Storage (§15, non-negotiable #9) --------------------------------
        // The bytes live outside PostgreSQL. Which store is an adapter: the
        // local one is what shared hosting offers, and swapping in S3 is
        // another StorageProvider and this line.
        StorageProvider::class => factory(
            static fn (): StorageProvider => new LocalStorageProvider(
                $env('ASSET_STORAGE_ROOT', sys_get_temp_dir() . '/backprod-assets'),
            ),
        ),

        AssetRepository::class => autowire(PostgresAssetRepository::class),

        // Fail-closed, like the payment and PDP secrets: with no key nothing
        // can be signed, and AssetLinks refuses to verify rather than treating
        // an empty key as valid.
        AssetLinks::class => factory(
            static fn (): AssetLinks => new AssetLinks($env('ASSET_LINK_SIGNING_SECRET')),
        ),

        // --- Jobs (§27, D3) --------------------------------------------------
        // The registry is where a job type becomes runnable. A type with no
        // entry here is refused at enqueue rather than claimed and retried
        // until its attempts run out.
        // Parameters are resolved by type, so the registry names its handlers
        // as types rather than pulling them out of the container by string —
        // which also means each one is a checked dependency, not a `mixed`.
        JobHandlers::class => factory(
            static fn (
                ExpireQuotes $quotes,
                ExpireSubscriptions $subscriptions,
                ExportProject $exports,
                DispatchNotifications $notify,
            ): JobHandlers => new JobHandlers([$quotes, $subscriptions, $exports, $notify]),
        ),

        NotificationRepository::class => autowire(PostgresNotificationRepository::class),

        // Notification channels (§27.1). Screen is real; the outbound three
        // are one honest stand-in configured per channel until a provider is
        // wired, because what differs between real SMTP and real SMS is
        // everything and what differs between three fakes is nothing.
        DispatchNotifications::class => factory(
            static fn (
                NotificationRepository $repository,
                Notifications $notifications,
                UserRepository $users,
                LoggerInterface $logger,
            ): DispatchNotifications => new DispatchNotifications(
                $repository,
                $notifications,
                $users,
                [
                    new ScreenChannel(),
                    new LogNotifier(Channel::EMAIL, $logger),
                    new LogNotifier(Channel::SMS, $logger),
                    new LogNotifier(Channel::WHATSAPP, $logger),
                ],
            ),
        ),

        JobRepository::class => autowire(PostgresJobRepository::class),

        // --- Messaging (§12.3) ----------------------------------------------
        // One repository serving two services: Conversations scopes every
        // query to a tenant and product, SupportDesk crosses that boundary
        // and records having done so. The separation is in the services and
        // in the SQL, not in a flag.
        ConversationRepository::class => autowire(PostgresConversationRepository::class),

        // --- Platform staff (§12.2) -----------------------------------------
        // Bound separately from the tenant repositories above, and reading
        // separate tables. Neither axis can resolve into the other.
        // Fiscalité (§25.3). The validator is a port like every other
        // provider: the stub answers deterministically so the fail-closed
        // path can be exercised, and a real VIES adapter replaces this line.
        TaxRepository::class => autowire(PostgresTaxRepository::class),
        VatNumberValidator::class => autowire(StubVatNumberValidator::class),

        StaffRepository::class => autowire(PostgresStaffRepository::class),
        StaffAccessLog::class => autowire(PostgresStaffAccessLog::class),
        TenantDirectory::class => autowire(PostgresTenantDirectory::class),

        // --- HTTP -----------------------------------------------------------
        // Four levels of protection, declared in one place. Anything not
        // listed gets the full §10.6 chain, so a new route is protected by
        // omission rather than by remembering to protect it.
        RoutePolicy::class => factory(
            static fn (): RoutePolicy => new RoutePolicy(
                publicPaths: ['/api/v1/health'],
                identityOnlyPaths: ['/api/v1/products'],
                // Unauthenticated, because the sender is a payment provider
                // rather than a person. Everything under it must verify its
                // own signature — see RoutePolicy and §24.
                // Two, and both authenticate the request itself rather than
                // the caller: a payment provider signs with its own secret, a
                // download link with ours. Nothing may be mounted under
                // either that does not verify its own signature.
                publicPrefixes: ['/api/v1/webhooks', '/api/v1/downloads'],
                // Authenticated and requiring a platform role, which no
                // membership grants. These routes resolve no tenant of their
                // own: they take one explicitly and record having read it.
                staffPrefixes: ['/api/v1/staff'],
            ),
        ),

        Dispatcher::class => factory(static function (): Dispatcher {
            $routes = require __DIR__ . '/routes.php';

            if (!is_callable($routes)) {
                throw new RuntimeException('config/routes.php must return a callable.');
            }

            return simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
                $routes($collector);
            });
        }),

        MiddlewarePipeline::class => autowire()
            ->constructorParameter('middleware', [
                get(RequestIdMiddleware::class),
                get(ErrorHandlerMiddleware::class),
                get(RequestContextMiddleware::class),
            ])
            ->constructorParameter('finalHandler', get(Router::class)),

        RequestHandlerInterface::class => get(MiddlewarePipeline::class),
    ]);

    $builder->addDefinitions($overrides);

    return $builder->build();
};
