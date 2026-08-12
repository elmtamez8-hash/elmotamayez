"use client";

import { COUNTRIES } from "@/lib/countries";
import { Select } from "./Field";

/**
 * Dial code + national number, emitting one E.164 string.
 *
 * The two halves are separate controls but one value: the API takes `+97455…`
 * and nothing else, so joining them here means no endpoint ever has to guess
 * which country a bare `55512345` belongs to.
 */
export function PhoneInput({
  id,
  dial,
  number,
  onDialChange,
  onNumberChange,
  error,
}: {
  id: string;
  dial: string;
  number: string;
  onDialChange: (dial: string) => void;
  onNumberChange: (number: string) => void;
  error?: string;
}) {
  const describedBy = error ? `${id}-error` : undefined;

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">
        رقم الجوال
      </label>

      <div className="flex gap-2">
        <Select
          value={dial}
          onChange={(event) => onDialChange(event.target.value)}
          aria-label="رمز الدولة"
          className="rounded-xl border border-line bg-surface px-3 py-2.5 text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        >
          {COUNTRIES.map((country) => (
            <option key={country.code} value={country.dial}>
              {country.dial} {country.name_ar}
            </option>
          ))}
        </Select>

        <input
          id={id}
          type="tel"
          inputMode="numeric"
          dir="ltr"
          value={number}
          onChange={(event) => onNumberChange(event.target.value.replace(/\D/g, ""))}
          placeholder="55512345"
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className="w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        />
      </div>

      {error && (
        <p id={describedBy} className="mt-1 text-sm text-danger-ink">
          {error}
        </p>
      )}
    </div>
  );
}

/** Joins the two halves into what the API expects. */
export function toE164(dial: string, number: string): string {
  return `${dial}${number.replace(/\D/g, "")}`;
}
