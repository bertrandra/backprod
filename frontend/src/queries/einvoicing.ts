import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * An invoice's journey to a certified platform (§25.1), in four states.
 *
 * **A rejection is a settled outcome, not an error to retry blindly.** The
 * contract says so, and it is the whole reason `REJECTED` sits beside `ACCEPTED`
 * rather than being reported as a failure: the platform read the document and
 * said no, with a code and a reason. Retrying the same bytes would get the same
 * answer.
 *
 * So the screen shows the progression with the current state named, the
 * rejection code and reason when there is one, and offers a resubmission only
 * where one could plausibly help.
 *
 * `settled` says whether the state can still move — the same flag payments use,
 * for the same reason: a callback arriving afterwards is ignored rather than
 * applied twice.
 */

export type Transmission = Schemas['Transmission'];
export type TransmissionStatus = Transmission['status'];

/** The four, in the order they happen. Used to draw the progression. */
export const TRANSMISSION_STATES: readonly TransmissionStatus[] = [
  'PENDING',
  'SUBMITTED',
  'ACCEPTED',
];

/**
 * How far along a transmission is.
 *
 * `REJECTED` is deliberately not a position on this line: it is where the
 * journey stopped, not how far it got, and drawing it as a fourth step would
 * suggest it comes after acceptance.
 */
export function progressOf(status: TransmissionStatus): number {
  const index = TRANSMISSION_STATES.indexOf(status);

  return index === -1 ? TRANSMISSION_STATES.length : index;
}

export function useTransmissions(invoiceId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.transmissions(invoiceId ?? ''),
    enabled: invoiceId !== null,
    queryFn: async (): Promise<readonly Transmission[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/billing/invoices/{invoiceId}/transmissions',
        { params: { ...ambient.params, path: { invoiceId: invoiceId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.transmissions;
    },
    refetchInterval: (query) => {
      const transmissions = query.state.data ?? [];

      // Something is still in flight at the platform, and the answer arrives by
      // callback rather than in a response — so the page asks.
      return transmissions.some((transmission) => !transmission.settled) ? 5_000 : false;
    },
  });
}

/**
 * Submitting.
 *
 * Answers 202: the document has been handed over, not accepted. What comes back
 * is a transmission in `PENDING` or `SUBMITTED`, and the acceptance — or the
 * rejection — arrives later.
 */
export function useSubmitInvoice(invoiceId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Transmission> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices/{invoiceId}/transmit',
        { params: { ...ambient.params, path: { invoiceId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: keys.billing.transmissions(invoiceId) }),
  });
}
