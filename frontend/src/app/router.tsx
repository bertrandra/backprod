import {
  createRootRoute,
  createRoute,
  createRouter,
  Outlet,
  type AnyRoute,
} from '@tanstack/react-router';

import { parseViewState, type ViewState } from '@/app/frame/viewState';
import { ProfileScreen } from '@/features/account/ProfileScreen';
import { BrandingScreen } from '@/features/branding/BrandingScreen';
import { ConversationsScreen } from '@/features/messaging/ConversationsScreen';
import { MembersScreen } from '@/features/members/MembersScreen';
import { NotificationSettingsScreen } from '@/features/notifications/NotificationSettingsScreen';
import { NotificationsScreen } from '@/features/notifications/NotificationsScreen';
import { OrganisationScreen } from '@/features/organisation/OrganisationScreen';
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

/** U1 ships no screens; each route renders a placeholder naming its milestone. */
function Placeholder({ area, milestone }: { area: string; milestone: string }) {
  return (
    <EmptyState
      title={`${area} arrives in ${milestone}`}
      description="The shell, its regions and this route exist. The screen does not yet."
    />
  );
}

const validateSearch = (search: Record<string, unknown>): ViewState => parseViewState(search);

interface Placeholded {
  path: string;
  area: string;
  milestone: string;
}

const TENANT_ROUTES: readonly Placeholded[] = [
  { path: '/projects', area: 'Projects', milestone: 'U4' },
  { path: '/jobs', area: 'Jobs', milestone: 'U4' },
  { path: '/catalogue', area: 'Catalogue', milestone: 'U5' },
  { path: '/quotes', area: 'Quotes', milestone: 'U5' },
  { path: '/orders', area: 'Orders', milestone: 'U5' },
  { path: '/subscription', area: 'Subscription', milestone: 'U6' },
  { path: '/invoices', area: 'Invoices', milestone: 'U6' },
  { path: '/tax', area: 'Tax', milestone: 'U7' },
];

/** The areas that have a real screen. Placeholders below are what is still to come. */
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
];

const CONSOLE_ROUTES: readonly Placeholded[] = [
  { path: '/console/tenants', area: 'Tenants', milestone: 'U8' },
  { path: '/console/conversations', area: 'Conversations', milestone: 'U8' },
  { path: '/console/access-log', area: 'Access log', milestone: 'U8' },
  { path: '/console/metrics', area: 'Metrics', milestone: 'U8' },
  { path: '/console/directory', area: 'Directory', milestone: 'U8' },
  { path: '/console/queue', area: 'Queue', milestone: 'U8' },
  { path: '/console/audit', area: 'Audit', milestone: 'U8' },
];

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

const indexRoute = createRoute({
  getParentRoute: () => tenantShellRoute,
  path: '/',
  validateSearch,
  component: () => <Placeholder area="The workspace" milestone="U4" />,
});

function placeholderRoutes(parent: AnyRoute, defs: readonly Placeholded[]): AnyRoute[] {
  return defs.map(({ path, area, milestone }) =>
    createRoute({
      getParentRoute: () => parent,
      path,
      validateSearch,
      component: () => <Placeholder area={area} milestone={milestone} />,
    }),
  );
}

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

const routeTree = rootRoute.addChildren([
  tenantShellRoute.addChildren([
    indexRoute,
    ...screenRoutes,
    ...placeholderRoutes(tenantShellRoute, TENANT_ROUTES),
    notFoundRoute,
  ]),
  consoleShellRoute.addChildren(placeholderRoutes(consoleShellRoute, CONSOLE_ROUTES)),
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
