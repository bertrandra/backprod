import { t } from '@/i18n';

/**
 * How often something is paid, in words the reader's language has.
 *
 * Six screens wrote `billing_period.toLowerCase()` and so put **monthly**
 * under a price on pages whose every other word was French. It reads like
 * data reaching the screen by accident, and it is: the contract's
 * enumeration rendered as if it were a sentence.
 *
 * It is not data. `MONTHLY` is what the platform *stores*; "par mois" is
 * what a person *reads*, and the distinction is the same one `Money` makes
 * between minor units and what `Intl` prints.
 *
 * A `switch` over the contract's own enumeration, which is not what §13
 * forbids: the rule is against branching on a **plan or a product** — on
 * what somebody bought — and this branches on how often they pay for it,
 * which every offer in every catalogue answers the same way.
 *
 * The default keeps the old rendering for a period the contract gains
 * before this function hears about it, so a new one reads as itself rather
 * than as nothing at all.
 */
export function billingPeriod(period: string): string {
  switch (period) {
    case 'MONTHLY':
      return t("monthly");
    case 'YEARLY':
      return t("yearly");
    case 'CUSTOM':
      return t("custom");
    default:
      return period.toLowerCase().replace(/_/g, ' ');
  }
}
