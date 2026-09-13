import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import {
  CATEGORIES,
  CHANNELS,
  CONSENT_CHANNELS,
  isUndisableable,
  useConsents,
  useGrantConsent,
  usePreferences,
  useRevokeConsent,
  useSavePreference,
  type Category,
  type Channel,
} from '@/queries/notifications';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `account.notification_settings` — where notifications go, and the consent that
 * allows it.
 *
 * Two rules from non-negotiable #24 shape this screen:
 *
 *   - **no security notification is disableable.** The switch is shown fixed on
 *     rather than hidden, because someone hunting for it should learn it does not
 *     exist instead of deciding the page is broken.
 *   - **SMS and WhatsApp need provable, revocable consent.** Granting one records
 *     where the opt-in came from — the evidence, not just the claim — so the form
 *     asks for it rather than inventing a value.
 */
const consentSchema = z.object({
  channel: z.enum(CONSENT_CHANNELS),
  purpose: z.string().trim().min(1, 'Say what the consent is for.'),
  source: z.string().trim().min(1, 'Record where the opt-in came from.'),
});

type ConsentValues = z.infer<typeof consentSchema>;

const preferenceKey = (category: Category, channel: Channel): string => `${category}.${channel}`;

export function NotificationSettingsScreen() {
  const preferences = usePreferences();
  const save = useSavePreference();
  const consents = useConsents();
  const grant = useGrantConsent();
  const revoke = useRevokeConsent();

  const form = useForm<ConsentValues>({
    resolver: zodResolver(consentSchema),
    defaultValues: { channel: 'EMAIL', purpose: '', source: '' },
  });

  if (preferences.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (preferences.error !== null) {
    return <ErrorSurface error={preferences.error} onRetry={() => void preferences.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-8">
      <section className="space-y-3">
        <h1 className="text-2xl font-semibold">Notification settings</h1>

        {save.error !== null && <ErrorSurface error={save.error} />}

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                <th className="py-2 pr-4 text-left text-xs uppercase tracking-wide text-subtle">
                  Category
                </th>
                {CHANNELS.map((channel) => (
                  <th
                    key={channel}
                    className="px-2 py-2 text-center text-xs uppercase tracking-wide text-subtle"
                  >
                    {channel}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {CATEGORIES.map((category) => (
                <tr key={category} className="border-t border-line">
                  <th scope="row" className="py-2 pr-4 text-left font-medium">
                    {category}
                    {isUndisableable(category) && (
                      <span className="ml-2 text-xs font-normal text-subtle">always on</span>
                    )}
                  </th>
                  {CHANNELS.map((channel) => {
                    const key = preferenceKey(category, channel);
                    const fixed = isUndisableable(category);
                    const enabled = fixed || (preferences.data[key] ?? false);

                    return (
                      <td key={channel} className="px-2 py-2 text-center">
                        <input
                          type="checkbox"
                          className="size-5"
                          aria-label={`${category} on ${channel}`}
                          checked={enabled}
                          // Disabled, not hidden: a security notification cannot
                          // be switched off and the control says so.
                          disabled={fixed || save.isPending}
                          onChange={(event) =>
                            save.mutate({ category, channel, enabled: event.target.checked })
                          }
                        />
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">Consents</h2>
        <p className="text-sm text-muted">
          SMS and WhatsApp need consent that can be proved and revoked. Revoking records a
          date — the row stays, because deleting it would destroy the proof that permission once
          existed.
        </p>

        {consents.isPending ? (
          <SkeletonRows rows={3} />
        ) : consents.error !== null ? (
          <ErrorSurface error={consents.error} onRetry={() => void consents.refetch()} />
        ) : consents.data.length === 0 ? (
          <EmptyState title="No consents recorded" description="Grant one below." />
        ) : (
          <ul className="space-y-2">
            {consents.data.map((consent) => (
              <li
                key={consent.id}
                data-testid="consent"
                data-live={consent.live ? 'true' : 'false'}
                className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm md:flex md:items-center md:gap-4"
              >
                <div className="min-w-0 md:flex-1">
                  <p className="font-medium">
                    {consent.channel} — {consent.purpose}
                  </p>
                  <p className="text-xs text-muted">
                    granted {new Date(consent.granted_at).toLocaleDateString()} via {consent.source}
                    {consent.revoked_at !== null &&
                      ` · revoked ${new Date(consent.revoked_at).toLocaleDateString()}`}
                  </p>
                </div>

                {consent.live ? (
                  <Button
                    type="button"
                    variant="danger"
                    pending={revoke.isPending}
                    onClick={() => revoke.mutate(consent.id)}
                  >
                    Revoke
                  </Button>
                ) : (
                  <span className="text-xs text-subtle">Revoked</span>
                )}
              </li>
            ))}
          </ul>
        )}

        {revoke.error !== null && <ErrorSurface error={revoke.error} />}

        <form
          className="max-w-md space-y-4 pt-2"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              grant.mutate(values, { onSuccess: () => form.reset() }),
            )(event);
          }}
        >
          <Field id="consent-channel" label="Channel">
            <select id="consent-channel" className={inputClass()} {...form.register('channel')}>
              {CONSENT_CHANNELS.map((channel) => (
                <option key={channel} value={channel}>
                  {channel}
                </option>
              ))}
            </select>
          </Field>

          <Field
            id="consent-purpose"
            label="Purpose"
            error={form.formState.errors.purpose?.message}
          >
            <input
              id="consent-purpose"
              className={inputClass(form.formState.errors.purpose !== undefined)}
              {...form.register('purpose')}
            />
          </Field>

          <Field
            id="consent-source"
            label="Source"
            hint="Where the opt-in came from — the evidence, not just the claim."
            error={form.formState.errors.source?.message}
          >
            <input
              id="consent-source"
              className={inputClass(form.formState.errors.source !== undefined)}
              {...form.register('source')}
            />
          </Field>

          {grant.error !== null && <ErrorSurface error={grant.error} />}

          <Button type="submit" pending={grant.isPending}>
            Grant consent
          </Button>
        </form>
      </section>
    </div>
  );
}
