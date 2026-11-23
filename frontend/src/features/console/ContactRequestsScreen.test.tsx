import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith } from '@/test-utils';

import { ContactRequestsScreen } from './ContactRequestsScreen';

const request = (id: string, handled: string | null) => ({
  id,
  product: 'plan',
  name: 'Ada',
  email: 'ada@example.test',
  message: 'Does it draw a pergola?',
  consent: 'I agree to be contacted about my message.',
  locale: 'fr',
  received_at: '2026-11-23T09:00:00Z',
  handled_at: handled,
});

describe('messages from product pages', () => {
  it('lists them with what each visitor agreed to, and marks one answered by asking the server', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/contact-requests': { data: { requests: [request('r-1', null), request('r-2', '2026-11-22T10:00:00Z')], total: 2, open: 1 } },
      'POST /api/v1/staff/contact-requests/{requestId}/handled': { data: { request: request('r-1', '2026-11-23T10:00:00Z') } },
    });

    renderWith(<ContactRequestsScreen />, client);

    await waitFor(() => expect(screen.getByTestId('contact-requests')).toBeTruthy());

    expect(screen.getByTestId('contact-open').textContent).toBe('1 to answer');
    expect(screen.getAllByText(/I agree to be contacted/)).toHaveLength(2);
    // Answered by mail, from the reader's own.
    expect(screen.getAllByRole('link', { name: 'ada@example.test' })[0]?.getAttribute('href')).toBe('mailto:ada@example.test');
    expect(screen.queryByTestId('handle-r-2')).toBeNull();

    fireEvent.click(screen.getByTestId('handle-r-1'));

    await waitFor(() => expect(requests.some((sent) => sent.method === 'POST')).toBe(true));
    expect(requests.find((sent) => sent.method === 'POST')?.pathParams).toEqual({ requestId: 'r-1' });
  });

  it('says how a page comes to take messages when none has arrived', async () => {
    const { client } = recordingClient({ 'GET /api/v1/staff/contact-requests': { data: { requests: [], total: 0, open: 0 } } });

    renderWith(<ContactRequestsScreen />, client);

    await waitFor(() => expect(screen.getByText('No messages yet')).toBeTruthy());
  });
});
