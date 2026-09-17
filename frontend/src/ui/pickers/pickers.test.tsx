import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

import { SearchPicker } from './SearchPicker';
import { CountrySelect, CurrencySelect, PersonSelect, personLabel, type Person } from './Select';
import { COUNTRY_CODES, countryOptions, currencyOptions } from './regions';

/**
 * The pickers exist so a code the API stores is chosen by the name a person
 * reads. What is worth holding: the list is the standard's, the order is the
 * reader's, and the value that leaves the control is always the code.
 */
describe('countries', () => {
  it('is the ISO 3166-1 list, every one of them named', () => {
    expect(COUNTRY_CODES).toHaveLength(249);
    expect(new Set(COUNTRY_CODES).size).toBe(249);

    const options = countryOptions('en');
    expect(options.find((o) => o.code === 'FR')?.name).toBe('France');
    // Named, not echoed: a code that only came back as itself would be a
    // list entry nobody can find by reading.
    expect(options.every((o) => o.name !== o.code)).toBe(true);
  });

  it('is ordered by the reader’s alphabet, not by code', () => {
    const en = countryOptions('en').map((o) => o.code);
    // "Åland Islands" sorts among the As for an English reader; by code (AX)
    // it would sit after Aruba (AW).
    expect(en.indexOf('AX')).toBeLessThan(en.indexOf('AW'));

    const de = countryOptions('de').map((o) => o.name);
    expect(de.indexOf('Deutschland')).toBeLessThan(de.indexOf('Frankreich'));
  });

  it('renders as a native select whose value is the code', () => {
    render(<CountrySelect aria-label="Country" emptyLabel="Choose…" defaultValue="" />);

    const select = screen.getByLabelText<HTMLSelectElement>('Country');
    expect(select.tagName).toBe('SELECT');
    expect(select.options).toHaveLength(250);

    fireEvent.change(select, { target: { value: 'JP' } });
    expect(select.value).toBe('JP');
    expect(select.selectedOptions[0]?.textContent).toMatch(/\(JP\)$/);
  });
});

describe('currencies', () => {
  it('lists what the browser knows, and keeps a held code the browser does not', () => {
    const codes = currencyOptions([], 'en').map((o) => o.code);
    expect(codes).toContain('EUR');
    expect(codes).toContain('JPY');
    expect(codes.every((code) => /^[A-Z]{3}$/.test(code))).toBe(true);

    // A saved value must stay selectable whatever the list: it is what the
    // document was priced in.
    expect(currencyOptions(['ZZZ']).map((o) => o.code)).toContain('ZZZ');
    // And an empty or malformed one is not smuggled in as an option.
    expect(currencyOptions(['', 'eur']).map((o) => o.code)).not.toContain('eur');
  });

  it('renders the held value as chosen', () => {
    render(<CurrencySelect aria-label="Currency" value="TND" onChange={() => undefined} />);

    const select = screen.getByLabelText<HTMLSelectElement>('Currency');
    expect(select.value).toBe('TND');
  });
});

describe('people', () => {
  const ada: Person = { id: 'u-ada', name: 'Ada', email: 'ada@acme.test' };
  const anon: Person = { id: 'u-anon', name: null, email: null };

  it('labels a person by name and address, and falls back to what there is', () => {
    expect(personLabel(ada)).toBe('Ada <ada@acme.test>');
    expect(personLabel({ ...ada, name: null })).toBe('ada@acme.test');
    expect(personLabel({ ...ada, email: '' })).toBe('Ada');
    expect(personLabel(anon)).toBe('u-anon');
  });

  it('selects by the id, shown as the name', () => {
    render(<PersonSelect aria-label="Who" people={[ada, anon]} emptyLabel="Choose…" defaultValue="" />);

    const select = screen.getByLabelText<HTMLSelectElement>('Who');
    expect([...select.options].map((o) => [o.value, o.textContent])).toEqual([
      ['', 'Choose…'],
      ['u-ada', 'Ada <ada@acme.test>'],
      ['u-anon', 'u-anon'],
    ]);
  });
});

describe('searching for a person', () => {
  const ada: Person = { id: 'u-ada', name: 'Ada', email: 'ada@acme.test' };
  const grace: Person = { id: 'u-grace', name: 'Grace', email: 'grace@acme.test' };

  function Harness({ results }: { results: readonly Person[] }) {
    const [query, setQuery] = useState('');
    const [value, setValue] = useState<Person | null>(null);

    return (
      <>
        <label htmlFor="who">Who</label>
        <SearchPicker
          id="who"
          query={query}
          onQueryChange={setQuery}
          results={query === '' ? [] : results}
          pending={false}
          value={value}
          onPick={setValue}
        />
        <output data-testid="chosen">{value?.id ?? ''}</output>
      </>
    );
  }

  it('is a combobox: typing opens the list, arrows move, Enter chooses, Change clears', () => {
    render(<Harness results={[ada, grace]} />);

    const input = screen.getByRole('combobox', { name: 'Who' });
    expect(input.getAttribute('aria-expanded')).toBe('false');

    fireEvent.change(input, { target: { value: 'a' } });
    expect(input.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getAllByRole('option')).toHaveLength(2);
    expect(screen.getByRole('option', { name: /Ada/ }).getAttribute('aria-selected')).toBe('true');

    fireEvent.keyDown(input, { key: 'ArrowDown' });
    expect(screen.getByRole('option', { name: /Grace/ }).getAttribute('aria-selected')).toBe('true');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/u-grace$/);

    fireEvent.keyDown(input, { key: 'Enter' });
    expect(screen.getByTestId('chosen').textContent).toBe('u-grace');
    // Read back as a person, with the id that will be sent beside the name.
    expect(screen.getByText('Grace <grace@acme.test>')).toBeTruthy();
    expect(screen.getByText('u-grace', { selector: 'code' })).toBeTruthy();
    expect(screen.queryByRole('combobox')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Change' }));
    expect(screen.getByTestId('chosen').textContent).toBe('');
    expect(screen.getByRole('combobox', { name: 'Who' })).toBeTruthy();
  });

  it('says when nobody matches, rather than showing an empty box', () => {
    render(<Harness results={[]} />);

    fireEvent.change(screen.getByRole('combobox', { name: 'Who' }), { target: { value: 'zed' } });
    expect(screen.getByText('Nobody matches.')).toBeTruthy();
  });
});
