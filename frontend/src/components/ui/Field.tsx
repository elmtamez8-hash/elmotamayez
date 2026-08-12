"use client";

import type { ReactNode, SelectHTMLAttributes } from "react";
import { ChevronDownIcon } from "@/components/icons";

/**
 * Form controls, wired for accessibility by construction.
 *
 * The panel's forms used bare `<label>` with no `htmlFor`: clicking a label did
 * nothing and a screen reader announced an unnamed input. Here the id is a
 * required prop and every aria attribute hangs off it, so there is no version of
 * these components that is wired wrong.
 *
 * `aria-describedby` AND `aria-invalid` together — the first makes the message
 * readable, the second makes the state announced. One without the other is half
 * a fix.
 */

const CONTROL =
  "w-full rounded-xl border bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-muted " +
  "transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary " +
  "disabled:cursor-not-allowed disabled:opacity-60";

function borderFor(error?: string) {
  return error ? "border-danger" : "border-line";
}

type Shared = {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  required?: boolean;
  disabled?: boolean;
};

/** Wrapper for a control this file does not cover (a file picker, a date range). */
export function Field({
  id,
  label,
  error,
  hint,
  required,
  children,
}: Shared & { children: ReactNode }) {
  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium text-ink">
        {label}
        {required && (
          <span className="text-danger-ink" aria-hidden="true">
            {" *"}
          </span>
        )}
      </label>

      {hint && (
        <p id={`${id}-hint`} className="text-xs text-ink-muted">
          {hint}
        </p>
      )}

      {children}

      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs font-medium text-danger-ink">
          {error}
        </p>
      )}
    </div>
  );
}

function aria({ id, error, hint }: Shared) {
  const described = [hint ? `${id}-hint` : null, error ? `${id}-error` : null]
    .filter(Boolean)
    .join(" ");

  return {
    "aria-describedby": described || undefined,
    "aria-invalid": error ? (true as const) : undefined,
  };
}

export function TextField(
  props: Shared & {
    value: string;
    onChange: (value: string) => void;
    // `datetime-local` because a session's start is a date AND a time; two
    // fields for one moment is two chances to save half of it.
    type?: "text" | "email" | "password" | "url" | "date" | "time" | "datetime-local" | "search";
    placeholder?: string;
    autoComplete?: string;
    minLength?: number;
    maxLength?: number;
  },
) {
  const { id, value, onChange, type = "text", placeholder, autoComplete, minLength, maxLength, required, disabled, error } = props;

  return (
    <Field {...props}>
      <input
        id={id}
        name={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        autoComplete={autoComplete}
        minLength={minLength}
        maxLength={maxLength}
        required={required}
        disabled={disabled}
        className={`${CONTROL} ${borderFor(error)}`}
        {...aria(props)}
      />
    </Field>
  );
}

/**
 * Separate from TextField because a number needs `inputMode` for the mobile
 * keypad and `<bdi>`-safe alignment — passing type="number" to a text field and
 * hoping is how a phone field ends up with a full QWERTY keyboard.
 */
export function NumberField(
  props: Shared & {
    value: string;
    onChange: (value: string) => void;
    min?: number;
    max?: number;
    step?: number;
    placeholder?: string;
  },
) {
  const { id, value, onChange, min, max, step, placeholder, required, disabled, error } = props;

  return (
    <Field {...props}>
      <input
        id={id}
        name={id}
        type="number"
        inputMode="numeric"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        min={min}
        max={max}
        step={step}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        className={`${CONTROL} ${borderFor(error)} text-start`}
        {...aria(props)}
      />
    </Field>
  );
}

export function TextareaField(
  props: Shared & {
    value: string;
    onChange: (value: string) => void;
    rows?: number;
    placeholder?: string;
  },
) {
  const { id, value, onChange, rows = 4, placeholder, required, disabled, error } = props;

  return (
    <Field {...props}>
      <textarea
        id={id}
        name={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        rows={rows}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        className={`${CONTROL} ${borderFor(error)}`}
        {...aria(props)}
      />
    </Field>
  );
}

/**
 * A styled `<select>` with a direction-aware chevron.
 *
 * ⚠️ THE NATIVE ARROW IS TURNED OFF, and that is the whole point of this
 * component existing. Every browser draws its own control at the inline end,
 * hard against the border with no padding of its own, and none of them let CSS
 * move it — so on an RTL page the arrow sat glued to the left edge of a pill
 * whose text had 1rem of breathing room on the right. Thirteen selects across
 * the app each reproduced it.
 *
 * `appearance-none` removes it; the icon below is an absolutely positioned
 * element using `end-3` — Tailwind's alias for `inset-inline-end`, a LOGICAL
 * property. It sits on the
 * left in Arabic and moves to the right by itself the day a second direction
 * ships — there is no `dir` check here and there must never be one, because a
 * direction read in JavaScript is a direction that is wrong during the first
 * paint.
 *
 * `pointer-events-none` on the icon: it is decoration over a real control, and
 * a click that lands on it must still open the menu.
 */
export function Select({
  className = "",
  chevron = "md",
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & {
  /**
   * How much room the icon gets. `sm` is for controls already running on tight
   * padding — the player's speed picker is `px-2 py-1`, and a 2.5rem lane on a
   * 2-character option is most of the control.
   */
  chevron?: "sm" | "md";
}) {
  const room = chevron === "sm" ? "pe-7" : "pe-10";
  const place = chevron === "sm" ? "end-1.5 h-3.5 w-3.5" : "end-3 h-4 w-4";

  return (
    <div className="relative">
      <select
        {...props}
        // The end padding reserves the icon's lane, so a long option label
        // cannot run underneath it.
        className={`${className} appearance-none ${room}`}
      />
      <ChevronDownIcon
        className={`pointer-events-none absolute ${place} top-1/2 -translate-y-1/2 text-ink-muted`}
        aria-hidden="true"
      />
    </div>
  );
}

export function SelectField(
  props: Shared & {
    value: string;
    onChange: (value: string) => void;
    /**
     * `disabled` on one option, not on the whole field: a type that is declared
     * but not built has to be VISIBLE and unpickable, and hiding it would be
     * silent about a plan the label states out loud (016 FR-046).
     */
    options: Array<{ value: string; label: string; disabled?: boolean }>;
    placeholder?: string;
  },
) {
  const { id, value, onChange, options, placeholder, required, disabled, error } = props;

  return (
    <Field {...props}>
      <Select
        id={id}
        name={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={required}
        disabled={disabled}
        className={`${CONTROL} ${borderFor(error)}`}
        {...aria(props)}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value} disabled={o.disabled}>
            {o.label}
          </option>
        ))}
      </Select>
    </Field>
  );
}

export function CheckboxField({
  id,
  label,
  checked,
  onChange,
  disabled,
}: {
  id: string;
  label: ReactNode;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <label htmlFor={id} className="flex cursor-pointer items-start gap-2 text-sm text-ink">
      <input
        id={id}
        name={id}
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        disabled={disabled}
        className="mt-0.5 h-4 w-4 shrink-0 rounded border-line accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      />
      <span>{label}</span>
    </label>
  );
}
