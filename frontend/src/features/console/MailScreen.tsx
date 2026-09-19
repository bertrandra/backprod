import { useState } from 'react';

import { currentLocale, LOCALE_NAMES, LOCALES, t } from '@/i18n';
import { useMailTemplates, useSendTestMail, useSetMailTemplates, type MailLocale, type MailTemplate } from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';

/**
 * `console.admin.mail` — the words the platform's mails say, and whether
 * they leave (2026-09-19).
 *
 * Four kinds of mail a person acts on from an inbox — the reset link, the
 * invitation, the password-changed notice, the address confirmation — each
 * with a subject and a body the platform administrator may rewrite, in
 * whatever language the platform speaks. `{link}` and `{email}` are filled
 * when the mail is sent; a misspelt placeholder stays as written, which is
 * how the editor sees it. Emptying both fields puts the default back.
 *
 * **Send me a test** sends that kind, rendered with a sample link, to the
 * administrator's own address — inside the request, so the mail host's
 * answer is seen now rather than in a job log. Refused plainly where
 * `MAIL_DSN` is empty: the templates are kept, nothing leaves, and the
 * screen says so at the top.
 */
export function MailScreen() {
  // The language being edited (ADR-050): one set of words per language the
  // platform speaks. It starts on the language the administrator reads in.
  const [locale, setEditing] = useState<MailLocale>(currentLocale());
  const templates = useMailTemplates(locale);
  const save = useSetMailTemplates();
  const test = useSendTestMail();

  // The drafts: what the editor holds, seeded from the server and re-seeded
  // when the server answers again (a save, a reload). Derived during render
  // from the answer that seeded them, the way React resets state on a prop
  // change, rather than in an effect that would paint the old words first.
  const [drafts, setDrafts] = useState<Record<string, { subject: string; body: string }>>({});
  const [seededFrom, setSeededFrom] = useState<unknown>(undefined);
  const [tested, setTested] = useState<{ type: string; to: string } | null>(null);

  if (templates.data !== undefined && templates.data !== seededFrom) {
    setSeededFrom(templates.data);
    const seeded: Record<string, { subject: string; body: string }> = {};

    for (const template of templates.data.templates) {
      seeded[template.type] = { subject: template.subject, body: template.body };
    }

    setDrafts(seeded);
  }

  if (templates.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (templates.error !== null) {
    return <ErrorSurface error={templates.error} onRetry={() => void templates.refetch()} />;
  }

  const current = templates.data;
  const dirty = current.templates.some(
    (template) => drafts[template.type]?.subject !== template.subject || drafts[template.type]?.body !== template.body,
  );

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Mail")}
        description={t("What the mails this platform sends say, and whether they leave.")}
      />

      <section
        data-testid="mail-live"
        data-live={current.live}
        className={`${notice(current.live ? 'success' : 'warning')} space-y-1 text-sm`}
      >
        {current.live ? (
          <p>
            <span className="font-medium">{t("Mail is on.")}</span> {t("A mail host is configured (")}<code>{t("MAIL_DSN")}</code>{t("); what is queued leaves with the jobs cron.")}</p>
        ) : (
          <>
            <p className="font-medium">{t("No mail leaves this deployment.")}</p>
            <p>
              <code>{t("MAIL_DSN")}</code> {t("is empty in")}{' '}<code>{'.env'}</code>{t(": resets, invitations and confirmations are recorded but never sent, and a test is refused. Set it —")}{' '}<code>{t("smtp://user:pass@host:port")}</code> {t("— and")}{' '}
              <code>{t("MAIL_FROM")}</code>{t(", then come back.")}</p>
          </>
        )}
      </section>

      <div role="tablist" aria-label={t("Language")} data-testid="mail-locales" className="flex flex-wrap gap-1 border-b border-line">
        {LOCALES.map((code) => (
          <button
            key={code}
            type="button"
            role="tab"
            aria-selected={code === locale}
            data-locale={code}
            className={`-mb-px border-b-2 px-3 py-1.5 text-sm ${code === locale ? 'border-accent font-medium text-accent-strong' : 'border-transparent text-muted hover:text-ink'}`}
            onClick={() => setEditing(code)}
          >
            {LOCALE_NAMES[code]}
          </button>
        ))}
      </div>
      {locale !== 'en' && (
        <p className="text-xs text-muted">
          {t("A kind with no words of its own in this language shows English's, which is what a person reading in it receives.")}</p>
      )}

      {save.error !== null && <ErrorSurface error={save.error} />}

      <div className="space-y-8">
        {current.templates.map((template) => (
          <TemplateEditor
            key={template.type}
            template={template}
            draft={drafts[template.type] ?? { subject: template.subject, body: template.body }}
            onChange={(draft) => setDrafts((all) => ({ ...all, [template.type]: draft }))}
            onReset={() => setDrafts((all) => ({ ...all, [template.type]: { subject: '', body: '' } }))}
            live={current.live}
            testing={test.isPending && test.variables?.type === template.type}
            tested={tested?.type === template.type ? tested.to : null}
            testError={test.variables?.type === template.type ? test.error : null}
            onTest={() =>
              test.mutate({ type: template.type, locale }, {
                onSuccess: (sent) => setTested({ type: template.type, to: sent.to }),
                onError: () => setTested(null),
              })
            }
          />
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
        <Button
          type="button"
          pending={save.isPending}
          disabled={!dirty}
          data-testid="save-templates"
          onClick={() => save.mutate({ templates: drafts, locale })}
        >
          {t("Save the words")}</Button>
        <span className="text-xs text-muted">
          {t("Emptying a subject and body puts its default back. Placeholders:")}{' '}<code>{'{link}'}</code>,{' '}
          <code>{'{email}'}</code>.
        </span>
        {save.isSuccess && !dirty && (
          <span data-testid="saved" role="status" className="text-xs text-success">
            {t("Saved.")}</span>
        )}
      </div>
    </div>
  );
}

function TemplateEditor({
  template,
  draft,
  onChange,
  onReset,
  live,
  testing,
  tested,
  testError,
  onTest,
}: {
  template: MailTemplate;
  draft: { subject: string; body: string };
  onChange: (draft: { subject: string; body: string }) => void;
  onReset: () => void;
  live: boolean;
  testing: boolean;
  tested: string | null;
  testError: unknown;
  onTest: () => void;
}) {
  const id = template.type.replace(/\./g, '-');

  return (
    <section data-testid={`template-${template.type}`} data-customised={template.customised} className="space-y-3">
      <div className="flex flex-wrap items-baseline gap-2">
        <h2 className="text-xl font-semibold">{template.type}</h2>
        {template.customised ? (
          <span className="text-xs text-accent-strong">{t("customised")}</span>
        ) : (
          <span className="text-xs text-subtle">{t("default")}</span>
        )}
      </div>
      <p className="text-sm text-muted">{template.about}</p>
      <p className="text-xs text-subtle">
        {t("Placeholders:")}{' '}{template.placeholders.map((placeholder) => `{${placeholder}}`).join(', ')}
      </p>

      <Field id={`${id}-subject`} label={t("Subject")}>
        <input
          id={`${id}-subject`}
          className={inputClass()}
          value={draft.subject}
          onChange={(event) => onChange({ ...draft, subject: event.target.value })}
        />
      </Field>
      <Field id={`${id}-body`} label={t("Body")}>
        <textarea
          id={`${id}-body`}
          rows={6}
          className={inputClass()}
          value={draft.body}
          onChange={(event) => onChange({ ...draft, body: event.target.value })}
        />
      </Field>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="secondary" onClick={onReset}>
          {t("Back to the default")}</Button>
        <Button
          type="button"
          variant="secondary"
          pending={testing}
          disabled={!live}
          data-testid={`test-${template.type}`}
          title={live ? undefined : t("No mail leaves this deployment: set MAIL_DSN first.")}
          onClick={onTest}
        >
          {t("Send me a test")}</Button>
        {tested !== null && (
          <span data-testid={`tested-${template.type}`} role="status" className="text-xs text-success">
            {t("Sent to")}{' '}{tested} {t("— check the inbox (and the spam folder).")}</span>
        )}
      </div>
      {testError !== null && testError !== undefined && <ErrorSurface error={testError} />}
    </section>
  );
}