"use client";

import { RadioField } from "@/components/ui/Field";
import { arabicNumber } from "@/lib/numerals";

/**
 * One 1–5 axis, as a radio group.
 *
 * ⚠️ RADIOS AND NOT FIVE BUTTONS, the rule `ReviewForm` already follows: a shared
 * `name` is what makes the choice exclusive to a screen reader and buys arrow-key
 * navigation and a single tab stop from the browser. Five buttons announce
 * nothing about being mutually exclusive, and reproducing that in our own
 * JavaScript would still not reach the accessibility tree.
 */
export function AxisScale({
  name,
  label,
  value,
  onChange,
  disabled,
}: {
  name: string;
  label: string;
  value: number;
  onChange: (value: number) => void;
  disabled?: boolean;
}) {
  return (
    <fieldset>
      <legend className="mb-2 text-sm font-medium text-ink">{label}</legend>
      <div className="flex flex-wrap gap-4">
        {[1, 2, 3, 4, 5].map((option) => (
          <RadioField
            key={option}
            id={`${name}-${option}`}
            name={name}
            // ⚠️ ARABIC-INDIC, like every other number in this product. A Latin «4»
            // beside «من ٥» is the tell of a translated layout rather than an
            // authored one — the defect `lib/numerals.ts` exists to close.
            label={arabicNumber(option)}
            checked={value === option}
            onChange={() => onChange(option)}
            disabled={disabled}
          />
        ))}
      </div>
    </fieldset>
  );
}
