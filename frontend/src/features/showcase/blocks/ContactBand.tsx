import { useState } from 'react';

import { useSendContactRequest } from '@/queries/showcase';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, inputClass } from '@/ui/Field';
import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ContactRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * A form a visitor writes to the people behind the product with
 * (2026-11-23).
 *
 * **Name, address, message, and a box to tick** — the sentence beside it is
 * the operator's, and it is kept with each message as the visitor read it.
 * A fifth field, `website`, is out of sight and out of the tab order: a
 * person leaves it empty and a robot fills it, and the server throws such a
 * message away while answering as though it had kept it.
 *
 * **Not sent from the editor.** The page drawn for its author has no product
 * to write to, so the button says where it works instead of sending a draft
 * to support.
 */
export function ContactBand({
  row,
  heading,
  productCode,
}: {
  row: ShowcaseRow<ContactRow> | null;
  heading?: BandHeading | undefined;
  productCode?: string | undefined;
}) {
  if (row === null) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.CONTACT.anchor}
      surface={BAND_META.CONTACT.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Write to us")}
      lede={heading?.lede ?? row.content.intro ?? undefined}
      data-testid="showcase-contact"
    >
      {productCode === undefined ? <ContactForm row={row} /> : <SendingForm row={row} productCode={productCode} />}
    </ShowcaseBand>
  );
}

function SendingForm({ row, productCode }: { row: ShowcaseRow<ContactRow>; productCode: string }) {
  const send = useSendContactRequest(productCode);

  if (send.isSuccess) {
    return (
      <p role="status" data-testid="contact-sent" className="max-w-prose rounded-card border border-line bg-canvas px-5 py-4 text-lg">
        {row.content.thanks ?? t("Thank you — we will answer soon.")}
      </p>
    );
  }

  return (
    <ContactForm
      row={row}
      sending={send.isPending}
      problem={send.error === null ? null : <ErrorSurface error={send.error} />}
      onSend={(message) => send.mutate(message)}
    />
  );
}

function ContactForm({
  row,
  sending = false,
  problem = null,
  onSend,
}: {
  row: ShowcaseRow<ContactRow>;
  sending?: boolean;
  problem?: React.ReactNode;
  onSend?: (message: { name: string; email: string; message: string; consent: boolean; website: string }) => void;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [consent, setConsent] = useState(false);
  const [website, setWebsite] = useState('');

  return (
    <form
      className="grid max-w-2xl gap-4"
      data-testid="contact-form"
      onSubmit={(event) => {
        event.preventDefault();
        onSend?.({ name, email, message, consent, website });
      }}
    >
      {problem}

      <div className="grid gap-4 sm:grid-cols-2">
        <label className="grid gap-1.5 text-sm font-medium">
          {t("Your name")}
          <input className={inputClass()} required maxLength={200} autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} />
        </label>
        <label className="grid gap-1.5 text-sm font-medium">
          {t("Your email")}
          <input className={inputClass()} type="email" required maxLength={254} autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} />
        </label>
      </div>

      <label className="grid gap-1.5 text-sm font-medium">
        {t("Your message")}
        <textarea className={inputClass()} required rows={5} maxLength={5000} value={message} onChange={(event) => setMessage(event.target.value)} />
      </label>

      {/* For robots only: hidden from sight, from the tab order and from
          screen readers, so no person ever fills it. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label>
          Website
          <input tabIndex={-1} autoComplete="off" value={website} onChange={(event) => setWebsite(event.target.value)} />
        </label>
      </div>

      <label className="flex items-start gap-3 text-sm">
        <input type="checkbox" required className="mt-1 size-4" checked={consent} onChange={(event) => setConsent(event.target.checked)} />
        <span>{row.content.consent}</span>
      </label>

      <div>
        {onSend === undefined ? (
          <p className="text-sm text-muted" data-testid="contact-not-here">
            {t("The form sends from the published page, not from the editor.")}
          </p>
        ) : (
          <Button type="submit" pending={sending} data-testid="contact-send">
            {t("Send")}
          </Button>
        )}
      </div>
    </form>
  );
}
