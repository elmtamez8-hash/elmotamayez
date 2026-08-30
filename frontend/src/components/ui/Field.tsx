"use client";

import { useState } from "react";
import type { ReactNode, SelectHTMLAttributes } from "react";
import { ChevronDownIcon, EyeIcon, EyeOffIcon } from "@/components/icons";

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
    /*
     * ⚠️ `"password"` IS DELIBERATELY ABSENT (spec 022 · FR-014). A password
     * field is {@link PasswordField}, which adds the show/hide toggle; leaving
     * the value in this union would keep a second spelling alive that renders a
     * field with no way to reveal it, and the two would drift screen by screen.
     * Same shape as `"number"`, which is missing here for the same reason
     * {@link NumberField} exists. Removing it is also what makes `tsc` walk
     * every existing call site for you.
     */
    type?: "text" | "email" | "url" | "date" | "time" | "datetime-local" | "search";
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
 * A password field with a show/hide toggle (spec 022 · FR-014).
 *
 * Separate from TextField for the reason NumberField is: the control needs a
 * second element inside it, and `"password"` is gone from that union so the
 * toggle-less spelling cannot be written at all.
 *
 * Three details are load-bearing and each is a bug that has shipped elsewhere:
 *
 * 1. `type="button"`. A bare button inside a form defaults to `submit`, so the
 *    first press of the eye would send a half-filled registration.
 * 2. The state is per-render and never persisted. A revealed password that
 *    survives a reload is a password left on the screen of an unattended
 *    machine.
 * 3. The label changes with the state and is read by a screen reader, because
 *    the icon alone says nothing to one.
 *
 * The browser's own reveal control is hidden in `globals.css` (`::-ms-reveal`):
 * two adjacent buttons for one job is a confusion, and only one of them is
 * ours to place.
 */
export function PasswordField(
  props: Shared & {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    autoComplete?: string;
    minLength?: number;
    maxLength?: number;
  },
) {
  const { id, value, onChange, placeholder, autoComplete, minLength, maxLength, required, disabled, error } = props;
  const [visible, setVisible] = useState(false);

  return (
    <Field {...props}>
      <div className="relative">
        <input
          id={id}
          name={id}
          type={visible ? "text" : "password"}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          autoComplete={autoComplete}
          minLength={minLength}
          maxLength={maxLength}
          required={required}
          disabled={disabled}
          // `pe-10` reserves the button's lane so a long value never runs under
          // it. Logical properties throughout — this product is RTL, and `pr-`
          // would put the reservation on the wrong side of every field.
          className={`${CONTROL} ${borderFor(error)} pe-10`}
          {...aria(props)}
        />
        <button
          // ⚠️ EXPLICIT: a bare button inside a form is a submit button.
          type="button"
          onClick={() => setVisible((shown) => !shown)}
          disabled={disabled}
          aria-label={visible ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
          aria-pressed={visible}
          className="absolute inset-y-0 end-0 flex items-center px-3 text-ink-muted transition hover:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-60"
        >
          {visible ? <EyeOffIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
        </button>
      </div>
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

/**
 * One choice out of a group — a real `<input type="radio">`, deliberately.
 *
 * ⚠️ A CHECKBOX WITH AN EXCLUSIVE onChange WOULD HAVE BEEN THE SMALLER DIFF AND IT
 * LIES TO EVERY SCREEN READER: it announces "checkbox", which means "these toggle
 * independently", and the one thing this control has to convey is that they do not.
 * A shared `name` also buys arrow-key navigation and single-tab-stop grouping from
 * the browser, which no amount of our own JavaScript would reproduce for free.
 */
export function RadioField({
  id,
  name,
  label,
  checked,
  onChange,
  disabled,
}: {
  id: string;
  /** Same value for every option in one group — this is what makes it exclusive. */
  name: string;
  label: ReactNode;
  checked: boolean;
  onChange: () => void;
  disabled?: boolean;
}) {
  return (
    <label htmlFor={id} className="flex cursor-pointer items-start gap-2 text-sm text-ink">
      <input
        id={id}
        name={name}
        type="radio"
        checked={checked}
        onChange={() => onChange()}
        disabled={disabled}
        className="mt-0.5 h-4 w-4 shrink-0 border-line accent-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      />
      <span>{label}</span>
    </label>
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
