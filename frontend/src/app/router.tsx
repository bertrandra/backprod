import {
  createRootRoute,
  createRoute,
  createRouter,
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
import { MetricsScreen } from '@/features/console/MetricsScreen';
import { ProductsScreen } from '@/features/console/ProductsScreen';
import { QueueScreen } from '@/features/console/QueueScreen';
import { StaffMembersScreen } from '@/features/console/StaffMembersScreen';
import { StaffTenantsScreen } from '@/features/console/StaffTenantsScreen';
import { StorefrontScreen } from '@/features/console/StorefrontScreen';
import { SupportConversationsScreen } from '@/features/console/SupportConversationsScreen';
import { SignInScreen } from '@/features/auth/SignInScreen';
import { ConsoleShell } from '@/app/shells/ConsoleShell';
import { TenantShell } from '@/app/shells/TenantShell';
import { EmptyState } from '@/ui/EmptyState';

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
 * The console's screens. **A separate array, under a separate shell route** — the
 * two trees share the root and nothing else, which is what makes U8's "a
 * tenant-app route is unreachable from the console and vice versa" a property of
 * the router rather than a convention.
 */
const CONSOLE_SCREEN_ROUTES: readonly { path: string; component: () => React.JSX.Element }[] = [
  { path: '/console/tenants', component: StaffTenantsScreen },
  { path: '/console/conversations', component: SupportConversationsScreen },
  { path: '/console/access-log', component: AccessLogScreen },
  { path: '/console/metrics', component: MetricsScreen },
  { path: '/console/directory', component: DirectoryScreen },
  { path: '/console/products', component: ProductsScreen },
  { path: '/console/catalogue', component: ConsoleCatalogueScreen },
  { path: '/console/invoicing', component: InvoicingScreen },
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
 */
const signInRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/sign-in',
  component: SignInScreen,
});

const tenantShellRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: 'tenant-shell',
  component: TenantShell,
});

const consoleShellRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: 'console-shell',
  component: ConsoleShell,
});

/**
 * The tenant application's landing.
 *
 * It used to say "the workspace arrives in U4", which was true when U1 wrote it
 * and false from U4 onwards — a placeholder outliving its milestone is a small
 * lie that nothing fails on. There are no placeholders left in either tree now,
 * so this points at the navigation rather than at a date.
 */
const indexRoute = createRoute({
  getParentRoute: () => tenantShellRoute,
  path: '/',
  validateSearch,
  component: () => (
    <EmptyState
      title="Choose an area"
      description="Everything this product offers is in the navigation. What you can reach is what your permissions and your plan allow."
    />
  ),
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
  getParentRoute: () => tenantShellRoute,
  path: '$',
  component: () => (
    <EmptyState title="No such page" description="The link may be old, or mistyped." />
  ),
});

const screenRoutes: AnyRoute[] = SCREEN_ROUTES.map(({ path, component }) =>
  createRoute({ getParentRoute: () => tenantShellRoute, path, validateSearch, component }),
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
  getParentRoute: () => tenantShellRoute,
  path: '/projects/$projectId',
  validateSearch,
  component: function ProjectRoute() {
    const projectId = useStringParam('projectId');

    // Unreachable through this route, which cannot match without the segment —
    // but the parameter is narrowed rather than asserted, so "no id" has an
    // answer instead of a cast that would be wrong exactly once.
    return projectId === null ? (
      <EmptyState title="No such project" description="The link may be old, or mistyped." />
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
  getParentRoute: () => tenantShellRoute,
  path: '/checkout/$sessionId',
  validateSearch,
  component: function CheckoutRoute() {
    const sessionId = useStringParam('sessionId');

    return sessionId === null ? (
      <EmptyState title="No such checkout" description="The link may be old, or mistyped." />
    ) : (
      <CheckoutScreen sessionId={sessionId} />
    );
  },
});

/** One invoice, addressed by its id — a document worth linking to. */
const invoiceRoute = createRoute({
  getParentRoute: () => tenantShellRoute,
  path: '/invoices/$invoiceId',
  validateSearch,
  component: function InvoiceRoute() {
    const invoiceId = useStringParam('invoiceId');

    return invoiceId === null ? (
      <EmptyState title="No such invoice" description="The link may be old, or mistyped." />
    ) : (
      <InvoiceScreen invoiceId={invoiceId} />
    );
  },
});

const routeTree = rootRoute.addChildren([
  // First, and a sibling of both shells rather than a child of either.
  signInRoute,
  tenantShellRoute.addChildren([
    indexRoute,
    ...screenRoutes,
    projectRoute,
    checkoutRoute,
    invoiceRoute,
    notFoundRoute,
  ]),
  consoleShellRoute.addChildren(
    CONSOLE_SCREEN_ROUTES.map(({ path, component }) =>
      createRoute({ getParentRoute: () => consoleShellRoute, path, validateSearch, component }),
    ),
  ),
]);

export function buildRouter() {
  return createRouter({
    routeTree,
    // Reached only if something falls outside every shell, which the catch-all
    // above makes unlikely — kept so such a case is still a page and not blank.
    defaultNotFoundComponent: () => (
      <EmptyState title="No such page" description="The link may be old, or mistyped." />
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
