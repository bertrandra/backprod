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
