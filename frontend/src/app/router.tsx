import {
  createRootRoute,
  createRoute,
  createRouter,
  Navigate,
  Outlet,
  type AnyRoute,
} from '@tanstack/react-router';

import { useStringParam } from '@/app/frame/routeParams';
import { parseViewState, type ViewState } from '@/app/frame/viewState';
import { ProfileScreen } from '@/features/account/ProfileScreen';
import { BrandingScreen } from '@/features/branding/BrandingScreen';
import { BillingProfileScreen } from '@/features/billing/BillingProfileScreen';
import { CreditNotesScreen } from '@/features/billing/CreditNotesScreen';
import { InvoiceScreen } from '@/features/billing/InvoiceScreen';
import { InvoicesScreen } from '@/features/billing/InvoicesScreen';
import { PaymentsScreen } from '@/features/billing/PaymentsScreen';
import { SubscriptionScreen } from '@/features/billing/SubscriptionScreen';
import { CatalogueScreen } from '@/features/commerce/CatalogueScreen';
import { ShowcaseScreen } from '@/features/showcase/ShowcaseScreen';
import { CheckoutScreen } from '@/features/commerce/CheckoutScreen';
import { OfferAuthoringScreen } from '@/features/commerce/OfferAuthoringScreen';
import { ConversationsScreen } from '@/features/messaging/ConversationsScreen';
import { MembersScreen } from '@/features/members/MembersScreen';
import { NotificationSettingsScreen } from '@/features/notifications/NotificationSettingsScreen';
import { NotificationsScreen } from '@/features/notifications/NotificationsScreen';
import { OrganisationScreen } from '@/features/organisation/OrganisationScreen';
import { OrdersScreen } from '@/features/sales/OrdersScreen';
import { QuotesScreen } from '@/features/sales/QuotesScreen';
import { TaxProfileScreen } from '@/features/tax/TaxProfileScreen';
import { TaxRatesScreen } from '@/features/tax/TaxRatesScreen';
import { VatReportsScreen } from '@/features/tax/VatReportsScreen';
import { JobsScreen } from '@/features/workspace/JobsScreen';
import { ProjectScreen } from '@/features/workspace/ProjectScreen';
import { ProjectsScreen } from '@/features/workspace/ProjectsScreen';
import { AccessLogScreen } from '@/features/console/AccessLogScreen';
import { AuditScreen } from '@/features/console/AuditScreen';
import { CatalogueScreen as ConsoleCatalogueScreen } from '@/features/console/CatalogueScreen';
import { DirectoryScreen } from '@/features/console/DirectoryScreen';
import { ErasureScreen } from '@/features/console/ErasureScreen';
import { InvoicingScreen } from '@/features/console/InvoicingScreen';
import { MailScreen } from '@/features/console/MailScreen';
import { StaffProfileScreen } from '@/features/console/StaffProfileScreen';
import { MenusScreen } from '@/features/console/MenusScreen';
import { MetricsScreen } from '@/features/console/MetricsScreen';
import { FeaturesScreen } from '@/features/console/FeaturesScreen';
import { ProductsScreen } from '@/features/console/ProductsScreen';
import { QueueScreen } from '@/features/console/QueueScreen';
import { ReadinessScreen } from '@/features/console/ReadinessScreen';
import { StaffMembersScreen } from '@/features/console/StaffMembersScreen';
import { StaffTenantsScreen } from '@/features/console/StaffTenantsScreen';
import { TenantWorkspaceScreen } from '@/features/console/TenantWorkspaceScreen';
import { StorefrontScreen } from '@/features/console/StorefrontScreen';
import { SupportConversationsScreen } from '@/features/console/SupportConversationsScreen';
import { DemoScreen } from '@/features/console/DemoScreen';
import { DemoPage } from '@/features/demo/DemoPage';
import { AppShell } from '@/app/shells/AppShell';
import { EmptyState } from '@/ui/EmptyState';
import { t } from '@/i18n';

/**
 * Two route trees, one router.
 *
 * Code-based rather than file-based routing, deliberately: the two shells must
 * be *visibly* separate, and a directory convention would express that
 * separation as a folder name. Here it is two arrays that share no parent but
 * the root, which is what UD4 decided.
 *
 * Every screen route validates its search params through the same parser, so
 * deep-linkable state is a property of the router rather than something each
 * screen remembers to implement.
 */

const rootRoute = createRootRoute({ component: () => <Outlet /> });

const validateSearch = (search: Record<string, unknown>): ViewState => parseViewState(search);

/** The tenant application's screens. Every area U2–U7 scheduled now has one. */
const SCREEN_ROUTES: readonly { path: string; component: () => React.JSX.Element }[] = [
  // U2
  { path: '/profile', component: ProfileScreen },
  { path: '/organisation', component: OrganisationScreen },
  { path: '/members', component: MembersScreen },
  { path: '/branding', component: BrandingScreen },
  // U3
  { path: '/notifications', component: NotificationsScreen },
  { path: '/notification-settings', component: NotificationSettingsScreen },
  { path: '/conversations', component: ConversationsScreen },
  // U4
  { path: '/projects', component: ProjectsScreen },
  { path: '/jobs', component: JobsScreen },
  // U5
  { path: '/catalogue', component: CatalogueScreen },
  { path: '/offers', component: OfferAuthoringScreen },
  { path: '/quotes', component: QuotesScreen },
  { path: '/orders', component: OrdersScreen },
  // U6
  { path: '/subscription', component: SubscriptionScreen },
  { path: '/invoices', component: InvoicesScreen },
  { path: '/payments', component: PaymentsScreen },
  { path: '/credit-notes', component: CreditNotesScreen },
  { path: '/billing-profile', component: BillingProfileScreen },
  // U7 — three areas, and the last placeholder inside the tenant shell goes
  // with them. What is left to build is the console, which has its own tree.
  { path: '/tax', component: TaxProfileScreen },
  { path: '/tax/rates', component: TaxRatesScreen },
  { path: '/tax/reports', component: VatReportsScreen },
];

/**
 * The platform's own screens, under the same shell as every other one.
 *
 * A separate array only because the paths share a prefix and reading them
 * together is useful. What keeps a tenant permission from reaching them is the
 * `scope` on each navigation entry and the gate on each endpoint — not which
 * array they were declared in.
 */
const PLATFORM_SCREEN_ROUTES: readonly { path: string; component: () => React.JSX.Element }[] = [
  // The landing, and the map: `/console` alone lands here too, so an
  // address typed without a section is a useful page rather than a 404.
  { path: '/console', component: ReadinessScreen },
  { path: '/console/readiness', component: ReadinessScreen },
  { path: '/console/tenants', component: StaffTenantsScreen },
  { path: '/console/conversations', component: SupportConversationsScreen },
  { path: '/console/access-log', component: AccessLogScreen },
  { path: '/console/metrics', component: MetricsScreen },
  { path: '/console/directory', component: DirectoryScreen },
  { path: '/console/products', component: ProductsScreen },
  { path: '/console/features', component: FeaturesScreen },
  { path: '/console/catalogue', component: ConsoleCatalogueScreen },
  { path: '/console/invoicing', component: InvoicingScreen },
  { path: '/console/menus', component: MenusScreen },
  { path: '/console/mail', component: MailScreen },
  { path: '/console/profile', component: StaffProfileScreen },
  { path: '/console/demo', component: DemoScreen },
  { path: '/console/staff', component: StaffMembersScreen },
  { path: '/console/storefront', component: StorefrontScreen },
  { path: '/console/queue', component: QueueScreen },
  { path: '/console/audit', component: AuditScreen },
  { path: '/console/erasure', component: ErasureScreen },
];

/**
 * Signing in, addressable but unlinked.
 *
 * Outside both shells, because a person here has no session and therefore no
 * navigation, no product and no permissions — a shell around this screen would be
 * a frame full of things that cannot be filled in.
 *
 * **Nothing navigates here.** `SignInGate` renders the same screen instead of the
 * shell for whatever URL was asked for, which is what lets a deep link survive
 * signing in. This route exists so the screen has an address of its own, and so
 * `identity.sign_in` can declare a route the way `gate:screens` requires of every
 * area.
 *
 * Which means the route itself only ever renders *signed in* — the gate has
 * shown the form and taken the password before the router mounts. Somebody
 * who typed `/sign-in` (the address the seed script points at) and signed in
 * there used to be shown the form a second time, signed in, with nowhere to
 * go (2026-09-20). The route now has nothing to show but the way out: the
 * landing, which settles their root, product and first screen, with the
 * search kept so `?product=` and `?lang=` survive.
 */
function SignedInAlready() {
  return <Navigate to="/" replace search={(previous: Record<string, unknown>) => previous} />;
}

const signInRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/sign-in',
  component: SignedInAlready,
});

/**
 * One shell for every screen a signed-in person can reach.
 *
 * There used to be two, and #22 was the reason given — wrongly. That rule is
 * about authorisation and the server enforces it; splitting the interface as
 * well only hid the platform's own screens from the person running it. The paths
 * are unchanged, so every link ever written still resolves.
 */
/**
 * The demonstration page (2026-09-18), outside the shell for the same reason
 * as signing in: whoever reads it may have no session. `SignInGate` renders
 * it for a stranger; this route renders it for somebody signed in.
 */
const demoRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/demo',
  component: DemoPage,
});

const appShellRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: 'app-shell',
  component: AppShell,
});

/**
 * The tenant application's landing: the product's story (2026-09-24).
 *
 * The root of an organisation — `hostname/acme/` — is one page seen by two
 * authorities: a stranger gets the storefront, a member gets the same six
 * bands with a different call to action (docs/tenant-roots.md §2.2,
 * docs/home-showcase-spec.md §2). Signing out returns here, which is why
 * the landing is the page a stranger and a member both know, rather than a
 * sentence pointing at the navigation.
 *
 * It was the **catalogue** until 2026-09-24, and a member never saw even
 * that: `useLanding` moved them on to the first entry of their menu the
 * moment they touched `/`. So the platform had three behaviours at one
 * address and no page that said what the product is. The prices are still
 * here — they are one of the six bands — and `/catalogue` still has them
 * on their own.
 */
const indexRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '/',
  validateSearch,
  component: ShowcaseScreen,
});

/**
 * A catch-all inside the tenant shell.
 *
 * Without it an unknown path falls to the router's own not-found, which renders
 * outside both shells — so a mistyped link would cost the person their
 * navigation and leave them with a bare sentence and no way onward. Handled
 * inside the frame instead.
 */
const notFoundRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '$',
  component: () => (
    <EmptyState title={t("No such page")} description={t("The link may be old, or mistyped.")} />
  ),
});

const screenRoutes: AnyRoute[] = SCREEN_ROUTES.map(({ path, component }) =>
  createRoute({ getParentRoute: () => appShellRoute, path, validateSearch, component }),
);

/**
 * The first route with a parameter in it.
 *
 * A project's id belongs in the path rather than in a search param: it is
 * *which* record, not how it is being looked at (ui-spec.md §4.3). The
 * distinction matters for the same reason `?selected=` does — a link has to
 * open the same project for whoever follows it.
 */
const projectRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '/projects/$projectId',
  validateSearch,
  component: function ProjectRoute() {
    const projectId = useStringParam('projectId');

    // Unreachable through this route, which cannot match without the segment —
    // but the parameter is narrowed rather than asserted, so "no id" has an
    // answer instead of a cast that would be wrong exactly once.
    return projectId === null ? (
      <EmptyState title={t("No such project")} description={t("The link may be old, or mistyped.")} />
    ) : (
      <ProjectScreen projectId={projectId} />
    );
  },
});

/**
 * A checkout, addressed by the order it is.
 *
 * The id in the path is the order's (ADR-034), which is what makes a dropped
 * connection survivable: the link is still valid afterwards and the order is
 * still in `/orders`. Nothing about a checkout lives only in the tab — except the
 * `client_secret`, which is deliberately not recoverable.
 */
const checkoutRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '/checkout/$sessionId',
  validateSearch,
  component: function CheckoutRoute() {
    const sessionId = useStringParam('sessionId');

    return sessionId === null ? (
      <EmptyState title={t("No such checkout")} description={t("The link may be old, or mistyped.")} />
    ) : (
      <CheckoutScreen sessionId={sessionId} />
    );
  },
});

/**
 * One customer, read from the console — addressed by id, so a link opens the
 * same customer for the next person (who is asked their own reason).
 */
const tenantWorkspaceRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '/console/tenants/$tenantId',
  validateSearch,
  component: function TenantWorkspaceRoute() {
    const tenantId = useStringParam('tenantId');

    return tenantId === null ? (
      <EmptyState title={t("No such customer")} description={t("The link may be old, or mistyped.")} />
    ) : (
      <TenantWorkspaceScreen tenantId={tenantId} />
    );
  },
});

/** One invoice, addressed by its id — a document worth linking to. */
const invoiceRoute = createRoute({
  getParentRoute: () => appShellRoute,
  path: '/invoices/$invoiceId',
  validateSearch,
  component: function InvoiceRoute() {
    const invoiceId = useStringParam('invoiceId');

    return invoiceId === null ? (
      <EmptyState title={t("No such invoice")} description={t("The link may be old, or mistyped.")} />
    ) : (
      <InvoiceScreen invoiceId={invoiceId} />
    );
  },
});

const platformScreenRoutes: AnyRoute[] = PLATFORM_SCREEN_ROUTES.map(({ path, component }) =>
  createRoute({ getParentRoute: () => appShellRoute, path, validateSearch, component }),
);

const routeTree = rootRoute.addChildren([
  // First, and a sibling of the shell rather than a child of it: a person here
  // has no session, so a frame would be full of things that cannot be filled in.
  signInRoute,
  demoRoute,
  appShellRoute.addChildren([
    indexRoute,
    ...screenRoutes,
    ...platformScreenRoutes,
    projectRoute,
    checkoutRoute,
    invoiceRoute,
    tenantWorkspaceRoute,
    // Last: it matches anything, so every real route has to be declared above it.
    notFoundRoute,
  ]),
]);

export function buildRouter(basepath = '') {
  return createRouter({
    routeTree,
    // The organisation's root, when the page is on one (`app/root.ts`): every
    // Link and navigate then carries it without being told.
    ...(basepath === '' ? {} : { basepath }),
    // Reached only if something falls outside every shell, which the catch-all
    // above makes unlikely — kept so such a case is still a page and not blank.
    defaultNotFoundComponent: () => (
      <EmptyState title={t("No such page")} description={t("The link may be old, or mistyped.")} />
    ),
    scrollRestoration: true,
  });
}

export type AppRouter = ReturnType<typeof buildRouter>;

declare module '@tanstack/react-router' {
  interface Register {
    router: AppRouter;
  }
}
