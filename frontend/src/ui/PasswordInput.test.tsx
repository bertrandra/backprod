import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PasswordInput } from './PasswordInput';

/**
 * The eye at the end of a password field (2026-09-24).
 *
 * What matters is that revealing changes the `type` and nothing else: the
 * value stays, `autoComplete` stays — so a password manager still knows
 * what the field is — and the button never submits the form it sits in.
 */
describe('a password field', () => {
  it('starts hidden, shows on demand, and hides again', () => {
    render(<PasswordInput id="p" defaultValue="hunter2" autoComplete="new-password" />);

    const field = screen.getByDisplayValue('hunter2');
    expect(field.getAttribute('type')).toBe('password');

    const eye = screen.getByTestId('toggle-password');
    expect(eye.getAttribute('aria-pressed')).toBe('false');

    fireEvent.click(eye);

    expect(field.getAttribute('type')).toBe('text');
    expect(eye.getAttribute('aria-pressed')).toBe('true');
    // The value and what the browser knows about the field are untouched.
    expect(screen.getByDisplayValue('hunter2').getAttribute('autocomplete')).toBe('new-password');

    fireEvent.click(eye);
    expect(field.getAttribute('type')).toBe('password');
  });

  it('does not submit the form it sits in', () => {
    let submitted = 0;

    render(
      <form onSubmit={() => { submitted += 1; }}>
        <PasswordInput id="p" defaultValue="hunter2" />
      </form>,
    );

    // A button inside a form submits it unless it says otherwise, and
    // revealing a password to check it is the last moment to send it.
    fireEvent.click(screen.getByTestId('toggle-password'));

    expect(submitted).toBe(0);
    expect(screen.getByTestId('toggle-password').getAttribute('type')).toBe('button');
  });
});
