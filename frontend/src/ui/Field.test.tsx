import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Field } from './Field';

/**
 * What a field owes the person who cannot see it.
 *
 * These exist because the thing they check was broken for the whole life of the
 * application and nothing noticed. `aria-describedby` was set on a `<div>`
 * wrapping the control, which describes the div: the hint and the error were
 * rendered, were referenced, and were unreachable from the input. Nothing is
 * *missing* in that markup, so axe passed every route in both themes; the
 * label/name/role checks passed too, because the label was always correct.
 *
 * The only check that catches it is this one — reading the attribute off the
 * control itself rather than trusting that it is somewhere nearby.
 */
describe('a field', () => {
  it('points the control at its hint', () => {
    render(
      <Field id="vat" label="VAT number" hint="Optional.">
        <input id="vat" />
      </Field>,
    );

    expect(screen.getByLabelText('VAT number').getAttribute('aria-describedby')).toBe('vat-hint');
  });

  it('points the control at its error', () => {
    render(
      <Field id="vat" label="VAT number" error="Not a VAT number.">
        <input id="vat" />
      </Field>,
    );

    expect(screen.getByLabelText('VAT number').getAttribute('aria-describedby')).toBe(
      'vat-error',
    );
  });

  it('points it at both, in reading order', () => {
    // Hint first: it says what the field wants, and the error says why what was
    // typed is not it. Reversed, the correction arrives before the rule.
    render(
      <Field id="vat" label="VAT number" hint="Optional." error="Not a VAT number.">
        <input id="vat" />
      </Field>,
    );

    expect(screen.getByLabelText('VAT number').getAttribute('aria-describedby')).toBe(
      'vat-hint vat-error',
    );
  });

  it('keeps a description the caller had already set', () => {
    render(
      <Field id="vat" label="VAT number" hint="Optional.">
        <input id="vat" aria-describedby="vat-extra" />
      </Field>,
    );

    expect(screen.getByLabelText('VAT number').getAttribute('aria-describedby')).toBe(
      'vat-extra vat-hint',
    );
  });

  it('describes nothing when there is nothing to say', () => {
    render(
      <Field id="vat" label="VAT number">
        <input id="vat" />
      </Field>,
    );

    expect(screen.getByLabelText('VAT number').hasAttribute('aria-describedby')).toBe(false);
  });

  it('announces the error without waiting to be asked', () => {
    // `role="alert"` so a correction reaches somebody who has already moved on
    // to the next field, rather than only when they come back.
    render(
      <Field id="vat" label="VAT number" error="Not a VAT number.">
        <input id="vat" />
      </Field>,
    );

    expect(screen.getByRole('alert').textContent).toBe('Not a VAT number.');
  });
});
