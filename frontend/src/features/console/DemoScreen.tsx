import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import {
  useDemoPage,
  useResetDemoWorld,
  useSetDemoPage,
  useStaffIdentity,
  type DemoWorld,
} from '@/queries/staff';
import { useSessionStore } from '@/state/session';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/**
 * `console.admin.demo` — the demonstration, on a screen of its own (2026-09-18).
 *
 * Two acts that have nothing to do with administering products, where they
 * used to sit: showing what the platform hosts to anybody (`/demo`, behind
 * `staff.demo.publish`), and putting the demonstration world back the way
 * it started (`staff.demo.reset`). Each panel shows itself to whoever holds
 * its permission; the menu entry shows for the first.
 */
export function DemoScreen() {
  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={t("Demonstration")}
        description={t("What this deployment shows to a visitor, and the world it is shown with.")}
      />

      <DemoPagePanel />

      <DemoWorldPanel />
    </div>
  );
}

/**
 * The public demonstration page's switch (2026-09-18).
 *
 * `/demo` shows what the platform hosts — products and offers, every
 * organisation with its people and their roles — to anybody, with no
 * session. That is a membership's answer everywhere else, so it is off
 * until whoever holds `staff.demo.publish` turns it on here, and the copy
 * says what is being published rather than calling it a feature flag.
 */
function DemoPagePanel() {
  const me = useStaffIdentity();
  const mayPublish = me.data?.permissions.includes('staff.demo.publish') ?? false;
  const page = useDemoPage(mayPublish);
  const set = useSetDemoPage();

  if (!mayPublish) {
    return null;
  }

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-page">
      <h2 className="text-xl font-semibold">{t("Demonstration page")}</h2>

      <p className="text-sm text-muted">
        <a href="/demo" className="underline underline-offset-2" target="_blank" rel="noreferrer">
          <code>{'/demo'}</code>
        </a>{' '}
        {t("shows anybody, with no sign-in, every product and its offers on sale, and every organisation with its address, its products, its subscriptions and its people with their roles. Everywhere else that is a member’s answer; switch it on only on a deployment that exists to be shown.")}</p>

      {page.error !== null && <ErrorSurface error={page.error} onRetry={() => void page.refetch()} />}
      {set.error !== null && <ErrorSurface error={set.error} />}

      {page.isPending ? (
        <SkeletonRows rows={1} />
      ) : (
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            data-testid="demo-page-switch"
            checked={page.data ?? false}
            disabled={set.isPending}
            onChange={(event) => set.mutate(event.target.checked)}
          />
          <span>
            {(page.data ?? false) ? 'Shown — /demo answers to anybody' : 'Hidden — /demo is a 404'}
          </span>
        </label>
      )}
    </section>
  );
}

/**
 * The demonstration world, put back the way it started.
 *
 * Offered only to whoever holds `staff.demo.reset` — PLATFORM_ADMIN alone —
 * and behind a second, explicit step, because it is the widest destructive
 * act on this platform: every product, organisation, invoice and person,
 * the reader included. The server refuses it anyway while a product that is
 * not the demonstration's exists; the copy says so rather than hiding the
 * button, so an administrator of a real deployment learns why it is not
 * for them.
 *
 * After it succeeds the reader's token names a row that no longer exists.
 * Nothing is refetched: the answer is shown — who to sign in as — and the
 * one button left signs out, which is the honest state.
 */
function DemoWorldPanel() {
  const me = useStaffIdentity();
  const reset = useResetDemoWorld();
  const queryClient = useQueryClient();
  const forget = useSessionStore((state) => state.forget);
  const [armed, setArmed] = useState(false);

  if (!(me.data?.permissions.includes('staff.demo.reset') ?? false)) {
    return null;
  }

  if (reset.data !== undefined) {
    return <DemoWorldReset world={reset.data} onSignOut={() => { forget(); queryClient.clear(); }} />;
  }

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-world">
      <h2 className="text-xl font-semibold">{t("Demonstration world")}</h2>

      <p className="text-sm text-muted">
        {t("Put the demonstration back the way it started: four products, two organisations, one person per role, two live subscriptions with their invoices. Everything else is emptied — every order, payment, invoice, conversation and account,")}{' '}<strong>{t("including yours")}</strong> {t("— and everybody is signed out. Refused while this platform hosts a product that is not the demonstration's.")}</p>

      {reset.error !== null && <ErrorSurface error={reset.error} />}

      {armed ? (
        <div className="space-y-3 rounded border border-danger/40 bg-danger/5 p-3" data-testid="demo-world-confirm">
          <p className="text-sm">
            {t("This cannot be undone. The four products, the two organisations and the six people come back; nothing done since the last reset survives.")}</p>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="danger"
              pending={reset.isPending}
              onClick={() => reset.mutate()}
              data-testid="demo-world-reset"
            >
              {t("Yes, wipe and reseed")}</Button>
            <Button variant="secondary" onClick={() => setArmed(false)} disabled={reset.isPending}>
              {t("Keep it")}</Button>
          </div>
        </div>
      ) : (
        <Button variant="danger" onClick={() => setArmed(true)} data-testid="demo-world-arm">
          {t("Reset the demonstration world…")}</Button>
      )}
    </section>
  );
}

function DemoWorldReset({ world, onSignOut }: { world: DemoWorld; onSignOut: () => void }) {
  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-world-done">
      <h2 className="text-xl font-semibold">{t("The demonstration world is back")}</h2>

      <p className="text-sm text-muted">
        {world.products.map((product) => product.name).join(', ')} {t("— with invoices")}{' '}
        {world.invoices.map((number) => <code key={number} className="mx-0.5">{number}</code>)}{t(". Your account was among the rows emptied, so sign in again as one of these; every one of them has the password")}{' '}<code>{world.password}</code>.
      </p>

      <ul className="space-y-1 text-sm" data-testid="demo-world-people">
        {world.people.map((person) => (
          <li key={person.email} className="flex flex-wrap items-baseline gap-x-2">
            <code className="select-all">{person.email}</code>
            <span className="text-muted">
              {person.role}
              {person.scope === 'tenant' ? ` · ${person.tenants.join(', ')}` : t(" · the console")}
            </span>
          </li>
        ))}
      </ul>

      <Button onClick={onSignOut} data-testid="demo-world-sign-in">
        {t("Sign in again")}</Button>
    </section>
  );
}

