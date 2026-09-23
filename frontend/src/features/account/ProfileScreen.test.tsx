import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { currentLocale, installCatalogue, setLocale } from '@/i18n';
import { recordingClient, renderWith, SESSION, type Stub } from '@/test-utils';

import { ProfileScreen } from './ProfileScreen';

/**
 * The profile's one structured field: the default product — where this
 * person's screens open when a link does not say which. Offered among the
 * products the server says they hold, sent as the code, and cleared as
 * null rather than as an empty string.
 */
const ATLAS = { id: 'prod-atlas', code: 'atlas', name: 'Atlas' };
const BOREAS = { id: 'prod-boreas', code: 'boreas', name: 'Boreas' };

const ME = { ...SESSION, permissions: [...SESSION.permissions, 'account.read', 'account.manage'] };

function clientFor(defaultProduct: string | null, patched: Record<string, unknown> = {}) {
  return recordingClient({
    'GET /api/v1/me': { data: ME },
    'GET /api/v1/products': { data: { products: [ATLAS, BOREAS], default: defaultProduct } },
    'PATCH /api/v1/me': (): Stub => ({
      data: { user_id: ME.user_id, email: ME.email, display_name: ME.display_name, default_product: 'boreas', ...patched },
    }),
  });
}

describe('the default product', () => {
  it("is offered among the person's own products, with the stored one chosen", async () => {
    const { client } = clientFor('atlas');
    renderWith(<ProfileScreen />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('default-product'));
    await waitFor(() => expect(select.value).toBe('atlas'));
    expect([...select.options].map((o) => o.value)).toEqual(['', 'atlas', 'boreas']);
  });

  it('is sent as the code, in the same patch as the name', async () => {
    const { client, requests } = clientFor('atlas');
    renderWith(<ProfileScreen />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('default-product'));
    await waitFor(() => expect(select.value).toBe('atlas'));

    fireEvent.change(select, { target: { value: 'boreas' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect((requests.find((r) => r.method === 'PATCH')?.body as { default_product?: unknown }).default_product).toBe('boreas');
  });

  it('"whichever comes first" is sent as null, not as an empty string', async () => {
    const { client, requests } = clientFor('atlas', { default_product: null });
    renderWith(<ProfileScreen />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('default-product'));
    await waitFor(() => expect(select.value).toBe('atlas'));

    fireEvent.change(select, { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect((requests.find((r) => r.method === 'PATCH')?.body as { default_product?: unknown }).default_product).toBeNull();
  });
});

describe('the language', () => {
  afterEach(async () => {
    await setLocale('en');
  });

  it('is offered among the languages the platform speaks, starting on the one in force', async () => {
    const { client } = clientFor('atlas');
    renderWith(<ProfileScreen />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('locale'));
    await waitFor(() => expect(select.value).toBe('en'));
    expect([...select.options].map((o) => o.value)).toEqual(['en', 'fr', 'es', 'de', 'it']);
  });

  it('is sent in the same patch, and applied the moment it is saved', async () => {
    installCatalogue('fr', { 'Your profile': 'Votre profil' });
    const { client, requests } = clientFor('atlas', { locale: 'fr' });
    renderWith(<ProfileScreen />, client);

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('locale'));
    fireEvent.change(select, { target: { value: 'fr' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect((requests.find((r) => r.method === 'PATCH')?.body as { locale?: unknown }).locale).toBe('fr');
    await waitFor(() => expect(currentLocale()).toBe('fr'));
  });
});

describe('a new password', () => {
  it('is asked for by mail, at the address this person is signed in with', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: ME },
      'GET /api/v1/products': { data: { products: [ATLAS], default: 'atlas' } },
      'POST /api/v1/auth/password/forgot': { data: { accepted: true }, status: 202 },
    });

    renderWith(<ProfileScreen />, client);

    // The consequence is on screen before the button is pressed, not after.
    const section = await screen.findByTestId('new-password');
    expect(section.textContent).toMatch(/thirty minutes/i);
    expect(section.textContent).toMatch(/ends every session/i);

    fireEvent.click(screen.getByTestId('ask-for-password-link'));

    await waitFor(() => expect(screen.getByTestId('password-link-sent')).toBeTruthy());
    // Their own address, taken from the session — never typed, so there is
    // nothing to mistype and nobody else's account to ask about.
    expect(requests.find((r) => r.path === '/api/v1/auth/password/forgot')?.body).toEqual({
      email: ME.email,
    });
  });

  it('offers nothing to an account with no address', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: { ...ME, email: null } },
      'GET /api/v1/products': { data: { products: [ATLAS], default: 'atlas' } },
    });

    renderWith(<ProfileScreen />, client);

    await screen.findByTestId('new-password');
    expect(screen.queryByTestId('ask-for-password-link')).toBeNull();
    expect(requests.some((r) => r.path.includes('password'))).toBe(false);
  });
});
