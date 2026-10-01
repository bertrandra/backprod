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

  // --- an absence is not a failure (2026-10-01) ------------------------------

  it('renders a missing record as an absence rather than a fault', () => {
    // Reported by the operator in their own words: "a message for no record is
    // not an error so it should not be in red — no need for try again." The
    // cause was here and not on any screen: the wording table had no entry for
    // an absence, so every one of them fell through to a red alert headed
    // "Something went wrong" with a retry that could not change the answer.
    render(
      <ErrorSurface
        error={new ApiError(404, 'SHOWCASE_NOT_FOUND', 'No such page.', {}, 'req-1')}
        onRetry={() => undefined}
      />,
    );

    // Not an alert: a screen reader announcing "nothing here yet" as one
    // interrupts for no reason.
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('status')).toBeTruthy();

    // The server's own sentence is the whole answer; the headline calling it a
    // fault is the part that misled.
    expect(screen.getByText('No such page.')).toBeTruthy();
    expect(screen.queryByText(/something went wrong/i)).toBeNull();

    // And no retry: it asks the same question and gets the same answer.
    expect(screen.queryByRole('button', { name: /try again/i })).toBeNull();
  });

  it('reads both of the platform’s conventions for an absence', () => {
    // `*_NOT_FOUND` and `NO_*`, matched as shapes rather than listed: a list is
    // a second place to remember, and the eighteenth code would go on reading
    // as a crash until somebody added it.
    for (const code of ['INVOICE_NOT_FOUND', 'NOT_FOUND', 'NO_SEAT', 'NO_SUBSCRIPTION']) {
      const { unmount } = render(
        <ErrorSurface error={new ApiError(404, code, 'Nothing there.', {}, 'r')} onRetry={() => undefined} />,
      );

      expect(screen.queryByRole('alert')).toBeNull();
      expect(screen.queryByRole('button', { name: /try again/i })).toBeNull();
      unmount();
    }
  });

  it('keeps a chosen wording even where the code looks like an absence', () => {
    // `SUBSCRIPTION_REQUIRED` and `NO_TENANT_ACCESS` are refusals with wording
    // of their own, and that wording is what sends somebody to the right
    // person. The table wins over the shape.
    render(<ErrorSurface error={new ApiError(403, 'NO_TENANT_ACCESS', 'x', {}, 'r')} onRetry={() => undefined} />);

    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText('You do not have access to this organisation')).toBeTruthy();
  });

  it('still shouts about a real failure', () => {
    render(<ErrorSurface error={new ApiError(500, 'INTERNAL_ERROR', 'Boom.', {}, 'r')} onRetry={() => undefined} />);

    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText('Something went wrong')).toBeTruthy();
    expect(screen.getByRole('button', { name: /try again/i })).toBeTruthy();
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
