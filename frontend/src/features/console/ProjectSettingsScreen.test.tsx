import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient } from '@/test-utils';

import { ProjectSettingsScreen } from './ProjectSettingsScreen';

/**
 * How large a project document may be (2026-10-07): the operator's choice
 * from the setup menu, rather than a constant changed by a release.
 *
 * The bounds and the host warning are the server's answers, so the stubs use
 * figures this screen could not have guessed.
 */
const ROUTE = { path: '/console/projects', initial: '/console/projects', product: null } as const;

const SETTINGS = {
  max_document_mib: 4,
  default_mib: 4,
  minimum_mib: 2,
  maximum_mib: 48,
  host_upload_bytes: 64 * 1024 * 1024,
  host_overrules: false,
};

describe('the document limit', () => {
  it('shows the limit in force and offers the bounds the server answered', async () => {
    renderAtRoute(
      <ProjectSettingsScreen />,
      stubClient({ 'GET /api/v1/staff/projects/settings': { data: SETTINGS } }),
      ROUTE,
    );

    const input = await screen.findByLabelText<HTMLInputElement>(/largest project document/i);
    expect(input.value).toBe('4');
    expect(input.min).toBe('2');
    expect(input.max).toBe('48');
    expect(screen.queryByTestId('host-overrules')).toBeNull();
  });

  it('sends the new limit and shows what the server wrote', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/projects/settings': { data: SETTINGS },
      'PUT /api/v1/staff/projects/settings': { data: { ...SETTINGS, max_document_mib: 12 } },
    });

    renderAtRoute(<ProjectSettingsScreen />, client, ROUTE);

    const input = await screen.findByLabelText<HTMLInputElement>(/largest project document/i);
    fireEvent.change(input, { target: { value: '12' } });
    fireEvent.click(screen.getByTestId('save-document-limit'));

    await waitFor(() =>
      expect(requests.find((r) => r.method === 'PUT' && r.path === '/api/v1/staff/projects/settings')?.body).toEqual({
        max_document_mib: 12,
      }),
    );
    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>(/largest project document/i).value).toBe('12'),
    );
  });

  it("says so when the host's upload ceiling overrules it — as the server decided", async () => {
    renderAtRoute(
      <ProjectSettingsScreen />,
      stubClient({
        'GET /api/v1/staff/projects/settings': {
          data: { ...SETTINGS, max_document_mib: 8, host_upload_bytes: 8 * 1024 * 1024, host_overrules: true },
        },
      }),
      ROUTE,
    );

    const warning = await screen.findByTestId('host-overrules');
    expect(warning.textContent).toMatch(/8 MB/);
  });
});
