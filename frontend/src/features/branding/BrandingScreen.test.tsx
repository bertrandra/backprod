import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient } from '@/test-utils';

import { BrandingScreen } from './BrandingScreen';

/**
 * The reason `tenant.branding` is scheduled in U2.
 *
 * Two gates that do not imply each other, and two refusals answered by different
 * people. A single "access denied" would send half the people who see it to the
 * wrong place — so the test is that the two read differently, not merely that
 * both refuse.
 */
const SKIN = { primary_color: '#112233', accent_color: null, logo_asset_id: null };

function clientFor(session: Record<string, unknown>) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/tenant/skin': { data: { skin: SKIN } },
  });
}

describe('the two refusals', () => {
  it('tells someone without the permission to ask an administrator', async () => {
    renderWith(
      <BrandingScreen />,
      clientFor({ ...SESSION, permissions: [], capabilities: ['white_label'] }),
    );

    await waitFor(() => expect(screen.getByText(/may not change the branding/i)).toBeTruthy());
    expect(screen.getByText(/administrator/i)).toBeTruthy();
    // No save button: hiding is courtesy, and the API refuses regardless.
    expect(screen.queryByRole('button', { name: /save colours/i })).toBeNull();
  });

  it('tells someone whose plan lacks the feature that it is an upgrade', async () => {
    renderWith(
      <BrandingScreen />,
      clientFor({ ...SESSION, permissions: ['skin.manage'], capabilities: [] }),
    );

    await waitFor(() => expect(screen.getByText(/plan does not include/i)).toBeTruthy());
    expect(screen.getByText(/upgrade/i)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /save colours/i })).toBeNull();
  });

  it('are different messages, not one generic refusal', async () => {
    const { unmount } = renderWith(
      <BrandingScreen />,
      clientFor({ ...SESSION, permissions: [], capabilities: ['white_label'] }),
    );
    await waitFor(() => expect(screen.getByText(/administrator/i)).toBeTruthy());
    const permissionText = document.body.textContent ?? '';
    unmount();

    renderWith(
      <BrandingScreen />,
      clientFor({ ...SESSION, permissions: ['skin.manage'], capabilities: [] }),
    );
    await waitFor(() => expect(screen.getByText(/upgrade/i)).toBeTruthy());
    const entitlementText = document.body.textContent ?? '';

    expect(permissionText).not.toBe(entitlementText);
  });

  it('lets someone with both edit', async () => {
    renderWith(<BrandingScreen />, clientFor(SESSION));

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /save colours/i })).toBeTruthy(),
    );
    expect(screen.queryByText(/plan does not include/i)).toBeNull();
    expect(screen.queryByText(/may not change the branding/i)).toBeNull();
  });
});

describe('reading a skin', () => {
  it('needs neither the permission nor the entitlement', async () => {
    // A client must know how to render itself before it knows what the tenant
    // bought (ui-spec.md §3.4), so the values are shown even when both gates say
    // no.
    renderWith(<BrandingScreen />, clientFor({ ...SESSION, permissions: [], capabilities: [] }));

    await waitFor(() => expect(screen.getByLabelText(/primary colour/i)).toBeTruthy());
    expect(screen.getByLabelText<HTMLInputElement>(/primary colour/i).value).toBe('#112233');
  });

  it('says the product default is used when no logo is set', async () => {
    renderWith(<BrandingScreen />, clientFor(SESSION));

    await waitFor(() => expect(screen.getByText(/No logo/i)).toBeTruthy());
  });
});
