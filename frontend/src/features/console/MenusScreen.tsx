import { useState } from 'react';

import { APP_NAV, type NavScope, type NavSection } from '@/app/frame/navigation';
import {
  AUDIENCES,
  useNavigationSetup,
  useSetNavigationSetup,
  type Audience,
  type AudienceMenu,
  type NavigationSetup,
} from '@/queries/navigation';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { PageHeader, Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * `console.admin.menus` — what the shell shows each kind of person.
 *
 * Three audiences, three checklists of the shell's own entries, and per
 * audience one more choice: whether an entry with nothing behind it is
 * shown at all. Decided by the platform administrator on 2026-09-17, for
 * the whole platform — the console is the same console whichever product
 * is chosen, and a customer's menu is a fact about the kind of person they
 * are rather than about what they bought.
 *
 * The list is `APP_NAV` itself, so an entry added to the shell appears
 * here without anybody remembering to: the setup stores what is switched
 * *off*, and a new entry is on until somebody says otherwise. The console's
 * checklist offers the platform entries and the two tenant-side ones offer
 * the tenant entries, because an audience only ever sees its own scope.
 *
 * What is ticked is what is *shown* — the checkbox reads the way a person
 * thinks about a menu — and it is the inverse that is sent. One entry is
 * never offered: this screen's own, which cannot be hidden from the person
 * who holds it, or the choice could not be undone.
 *
 * Saved whole, on one button, after the person has finished: three lists
 * changing on every tick would be three writes to the access log per
 * click, and a half-made choice recorded as a decision.
 */
const AUDIENCE: Record<Audience, { label: string; scope: NavScope; who: string }> = {
  platform_admin: {
    label: 'Platform administrator',
    scope: 'platform',
    who: 'The console — everybody holding a platform role, whichever role.',
  },
  tenant_admin: {
    label: 'Tenant administrator',
    scope: 'tenant',
    who: 'The customer’s own administrator: TENANT_ADMIN on their membership.',
  },
  user: {
    label: 'User',
    scope: 'tenant',
    who: 'Every other member of a customer’s organisation.',
  },
};

const UNHIDEABLE = new Set(['menus']);

function sectionsFor(scope: NavScope): readonly NavSection[] {
  return APP_NAV.map((section) => ({
    ...section,
    entries: section.entries.filter((entry) => entry.scope === scope && !UNHIDEABLE.has(entry.id)),
  })).filter((section) => section.entries.length > 0);
}

export function MenusScreen() {
  const setup = useNavigationSetup();
  const save = useSetNavigationSetup();
  // The person's edits, or nothing: what is shown is the edits while there
  // are any and the stored document otherwise, so a save — which writes the
  // server's reply into the cache — is also what clears the edits.
  const [edits, setEdits] = useState<NavigationSetup | null>(null);

  if (setup.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (setup.error !== null) {
    return <ErrorSurface error={setup.error} onRetry={() => void setup.refetch()} />;
  }

  const stored = setup.data;
  const draft = edits ?? stored;
  const dirty = edits !== null && JSON.stringify(edits) !== JSON.stringify(stored);

  // Functional, so two ticks in one breath both land: each change is applied
  // to the edits as they are at that moment, never to the render's copy.
  const update = (audience: Audience, change: (menu: AudienceMenu) => AudienceMenu) =>
    setEdits((current) => {
      const base = current ?? stored;

      return { ...base, [audience]: change(base[audience]) };
    });

  return (
    <div className="max-w-4xl space-y-8">
      <PageHeader
        title={t("Menus")}
        description={
          t("What the navigation shows each kind of person, for the whole platform. Untick an entry to leave it out of that audience’s menu; a section with nothing left disappears. Hiding is courtesy — the API refuses on permissions regardless — so this decides what people find, not what they may do.")
        }
      />

      {save.error !== null && <ErrorSurface error={save.error} />}

      <form
        className="space-y-8"
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate(draft, { onSuccess: () => setEdits(null) });
        }}
      >
        {AUDIENCES.map((audience) => (
          <AudiencePanel
            key={audience}
            audience={audience}
            menu={draft[audience]}
            onChange={(change) => update(audience, change)}
          />
        ))}

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" pending={save.isPending} disabled={!dirty}>
            {t("Save menus")}</Button>
          {save.isSuccess && !dirty && (
            <span data-testid="menus-saved" className="text-sm text-muted">
              {t("Saved — everybody sees it on their next screen.")}</span>
          )}
        </div>
      </form>
    </div>
  );
}

function AudiencePanel({
  audience,
  menu,
  onChange,
}: {
  audience: Audience;
  menu: AudienceMenu;
  onChange: (change: (menu: AudienceMenu) => AudienceMenu) => void;
}) {
  const { label, scope, who } = AUDIENCE[audience];
  const hidden = new Set(menu.hidden);

  const toggle = (id: string, shown: boolean) =>
    onChange((current) => {
      const next = new Set(current.hidden);

      if (shown) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return { ...current, hidden: [...next].sort() };
    });

  return (
    <Section
      title={t(label)}
      description={t(who)}
      data-testid={`menus-${audience}`}
      className="rounded-card border border-line bg-surface p-4 shadow-raise"
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {sectionsFor(scope).map((section) => (
          <fieldset key={section.id} className="space-y-1.5">
            <legend className="text-xs font-semibold uppercase tracking-wide text-subtle">{t(section.label)}</legend>
            {section.entries.map((entry) => {
              const shown = !hidden.has(entry.id);

              return (
                <label
                  key={entry.id}
                  className={cn('flex items-center gap-2 text-sm', !shown && 'text-muted')}
                >
                  <input
                    type="checkbox"
                    className="size-4"
                    data-entry={entry.id}
                    checked={shown}
                    onChange={(event) => toggle(entry.id, event.target.checked)}
                  />
                  <span>{t(entry.label)}</span>
                </label>
              );
            })}
          </fieldset>
        ))}
      </div>

      {/* The second option, with its consequence beside it: an entry whose
          screen would show an empty state is left out until it has something
          — for the entries the platform can count. */}
      <label className="flex items-start gap-2.5 rounded-control border border-line bg-well p-3 text-sm">
        <input
          type="checkbox"
          className="mt-0.5 size-4 shrink-0"
          data-hide-empty={audience}
          checked={menu.hide_empty}
          onChange={(event) => {
            const hideEmpty = event.target.checked;
            onChange((current) => ({ ...current, hide_empty: hideEmpty }));
          }}
        />
        <span>
          {t("Hide entries with nothing to show")}<span className="mt-0.5 block text-xs text-muted">
            {t("Lists that are empty for the reader — invoices before the first invoice, projects before the first project — are left out until they have something. Settings and profiles always show.")}</span>
        </span>
      </label>
    </Section>
  );
}
