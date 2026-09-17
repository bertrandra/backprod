import { forwardRef, useMemo, type SelectHTMLAttributes } from 'react';

import { inputClass } from '@/ui/Field';
import { countryOptions, currencyOptions, type Option } from '@/ui/pickers/regions';

/**
 * Pickers for the fields whose value is a code from a closed vocabulary —
 * a country, a currency, a person — built on the native `<select>`.
 *
 * Native on purpose. A `<select>` is the one control every platform already
 * renders well for a long list: a phone opens its own wheel or sheet, a
 * screen reader reads the options, the keyboard types ahead by name, and
 * none of that had to be written or can regress. A custom combobox would
 * buy searching-as-you-type at the price of reimplementing all of it, and
 * for two hundred and forty-nine countries type-ahead is already searching.
 * Where the list is genuinely open-ended — the platform's whole directory of
 * people — a search is the right control, and that one is {@see SearchPicker}.
 *
 * Each accepts everything a `<select>` does, forwards its ref, and so drops
 * straight into `form.register(...)` or a `value`/`onChange` pair alike. The
 * value is always the code: what the API stores, never what the person read.
 */

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> & {
  invalid?: boolean;
  /** The label of the empty option, shown when the field may be left unset. */
  emptyLabel?: string | undefined;
};

function Options({ options, emptyLabel }: { options: readonly Option[]; emptyLabel: string | undefined }) {
  return (
    <>
      {emptyLabel !== undefined && <option value="">{emptyLabel}</option>}
      {options.map((option) => (
        <option key={option.code} value={option.code}>
          {option.name} ({option.code})
        </option>
      ))}
    </>
  );
}

export const CountrySelect = forwardRef<HTMLSelectElement, SelectProps>(function CountrySelect(
  { invalid = false, emptyLabel, className, ...rest },
  ref,
) {
  const options = useMemo(() => countryOptions(), []);

  return (
    <select ref={ref} className={className ?? inputClass(invalid)} data-picker="country" {...rest}>
      <Options options={options} emptyLabel={emptyLabel} />
    </select>
  );
});

export const CurrencySelect = forwardRef<HTMLSelectElement, SelectProps>(function CurrencySelect(
  { invalid = false, emptyLabel, className, value, defaultValue, ...rest },
  ref,
) {
  // The value already held is kept in the list even where the browser's own
  // list lacks it, so a saved code never renders as "nothing chosen".
  const held = [value, defaultValue].filter((v): v is string => typeof v === 'string' && v !== '').join(' ');
  const options = useMemo(() => currencyOptions(held.split(' ')), [held]);

  return (
    <select
      ref={ref}
      className={className ?? inputClass(invalid)}
      data-picker="currency"
      {...(value !== undefined ? { value } : {})}
      {...(defaultValue !== undefined ? { defaultValue } : {})}
      {...rest}
    >
      <Options options={options} emptyLabel={emptyLabel} />
    </select>
  );
});

export type Person = { readonly id: string; readonly name: string | null; readonly email: string | null };

/**
 * A person by name, with the address beside it so two Adas stay apart. The
 * value is the user id — which is what every API that names a person takes,
 * and what nobody should have to copy from another screen.
 */
export function personLabel(person: Person): string {
  const name = person.name === null || person.name === '' ? null : person.name;
  const email = person.email === null || person.email === '' ? null : person.email;

  if (name !== null && email !== null) return `${name} <${email}>`;

  return name ?? email ?? person.id;
}

export const PersonSelect = forwardRef<HTMLSelectElement, SelectProps & { people: readonly Person[] }>(
  function PersonSelect({ people, invalid = false, emptyLabel, className, ...rest }, ref) {
    return (
      <select ref={ref} className={className ?? inputClass(invalid)} data-picker="person" {...rest}>
        {emptyLabel !== undefined && <option value="">{emptyLabel}</option>}
        {people.map((person) => (
          <option key={person.id} value={person.id}>
            {personLabel(person)}
          </option>
        ))}
      </select>
    );
  },
);
