import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith } from '@/test-utils';

import { ContactBand } from './ContactBand';

const ROW = {
  id: 'c',
  content: { intro: 'Ask us anything.', consent: 'I agree to be contacted about my message.', thanks: 'We will call you back.' },
  image: null,
};

/**
 * The contact form (2026-11-23). What is claimed is what reaches the
 * server, and what does not: a draft in the editor sends nothing.
 */
describe('a contact form', () => {
  it('sends what was typed, the box ticked, and shows the operator’s thanks', async () => {
    const { client, requests } = recordingClient({
      'POST /api/v1/public/products/{code}/contact': { status: 202, data: { received: true } },
    });

    renderWith(<ContactBand row={ROW} productCode="plan" />, client);

    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Ada' } });
    fireEvent.change(screen.getByLabelText('Your email'), { target: { value: 'ada@example.test' } });
    fireEvent.change(screen.getByLabelText('Your message'), { target: { value: 'Does it draw a pergola?' } });
    fireEvent.click(screen.getByLabelText('I agree to be contacted about my message.'));
    fireEvent.submit(screen.getByTestId('contact-form'));

    await waitFor(() => expect(screen.getByTestId('contact-sent').textContent).toBe('We will call you back.'));

    const sent = requests.find((request) => request.method === 'POST');

    expect(sent?.body).toEqual({ name: 'Ada', email: 'ada@example.test', message: 'Does it draw a pergola?', consent: true, website: '' });
    expect(sent?.pathParams).toEqual({ code: 'plan' });
  });

  it('keeps the robots’ field out of sight and out of reach', () => {
    renderWith(<ContactBand row={ROW} productCode="plan" />, recordingClient({}).client);

    const trap = screen.getByTestId('contact-form').querySelector('[aria-hidden="true"] input');

    expect(trap?.getAttribute('tabindex')).toBe('-1');
  });

  it('sends nothing from the editor, and says where it works', () => {
    const { client, requests } = recordingClient({});

    renderWith(<ContactBand row={ROW} />, client);

    expect(screen.queryByTestId('contact-send')).toBeNull();
    expect(screen.getByTestId('contact-not-here')).toBeTruthy();

    fireEvent.submit(screen.getByTestId('contact-form'));

    expect(requests).toHaveLength(0);
  });
});
