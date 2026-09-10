import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ApiError, toApiError } from '@/queries/session';

import { ErrorSurface } from './ErrorSurface';

/**
 * §10.4 says every failure has one shape. This checks the shape is *used*: the
 * code decides the wording, the request id is quoted, and a body that is not
 * the envelope still produces something true.
 */
describe('a failure on screen', () => {
  it('chooses its wording from the code, not the message', () => {
    render(
      <ErrorSurface
        error={new ApiError(403, 'PERMISSION_DENIED', 'Nope.', { permission: 'billing.read' }, 'req-1')}
      />,
    );

    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText('You do not have access to this')).toBeTruthy();
    // The API's sentence is still shown — it is usually better than a generic one.
    expect(screen.getByText('Nope.')).toBeTruthy();
  });

  it('distinguishes a refusal an administrator answers from one an upgrade answers', () => {
    const { unmount } = render(
      <ErrorSurface error={new ApiError(403, 'PERMISSION_DENIED', 'x', {}, 'r')} />,
    );
    expect(screen.getByText(/administrator/i)).toBeTruthy();
    unmount();

    render(<ErrorSurface error={new ApiError(403, 'ENTITLEMENT_REQUIRED', 'x', {}, 'r')} />);
    expect(screen.getByText(/upgrade/i)).toBeTruthy();
  });

  it('quotes the request id, because the server log is keyed by it', () => {
    render(<ErrorSurface error={new ApiError(500, 'INTERNAL_ERROR', 'x', {}, 'req-abc')} />);

    expect(screen.getByText(/req-abc/)).toBeTruthy();
  });

  it('renders the details that name the field at fault', () => {
    render(
      <ErrorSurface
        error={new ApiError(400, 'VALIDATION_FAILED', 'x', { field: 'limit', requirement: 'must be between 1 and 200' }, 'r')}
      />,
    );

    expect(screen.getByText(/field: limit/)).toBeTruthy();
    expect(screen.getByText(/must be between 1 and 200/)).toBeTruthy();
  });

  it('says something true when the body is not the envelope at all', () => {
    // A gateway returning HTML, or a proxy timing out. The contract promises the
    // envelope; the network does not.
    const error = toApiError(502, '<html>Bad Gateway</html>');

    render(<ErrorSurface error={error} />);

    expect(screen.getByText('The server answered unexpectedly')).toBeTruthy();
    // Nothing of the raw body reaches the screen.
    expect(screen.queryByText(/html/i)).toBeNull();
  });

  it('handles something that is not an ApiError at all', () => {
    render(<ErrorSurface error={new TypeError('network down')} />);

    expect(screen.getByText('Something went wrong')).toBeTruthy();
  });
});
