"use client";

import type { ReactNode } from "react";

/*
 * The parts every menu of the board's panel is made of, so they look and answer
 * alike (owner, 2026-10-06): a labelled row, the choices in it, and a select.
 * Before, «أدوات» showed bare words, «تشجيع» the same words with icons, and
 * the selects came in two shapes.
 */

/** A row of choices under its name; the names line up down the menu. */
export function MenuRow({ label, children }: { label?: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[4.5rem_1fr] items-start gap-2">
      <span className="pt-1.5 text-xs font-semibold text-ink-muted">{label}</span>
      <div className="flex flex-wrap gap-1.5">{children}</div>
    </div>
  );
}

/**
 * One choice: always looks pressable, and the one chosen (or open) stands out.
 * `active` is a choice held (a template, an instrument open); `expanded` a tool it opens.
 */
export function MenuChip({
  active,
  expanded,
  disabled,
  loading,
  loadingLabel,
  icon,
  onClick,
  children,
}: {
  active?: boolean;
  expanded?: boolean;
  disabled?: boolean;
  loading?: boolean;
  loadingLabel?: string;
  icon?: ReactNode;
  onClick: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      aria-expanded={expanded}
      aria-pressed={expanded === undefined && active !== undefined ? active : undefined}
      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition duration-150 ease-out active:scale-[0.96] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 ${
        active ? "border-primary bg-primary-soft text-primary-ink" : "border-line bg-surface-raised text-ink hover:border-primary hover:bg-primary-soft hover:text-primary-ink"
      }`}
    >
      {icon}
      {loading ? (loadingLabel ?? children) : children}
    </button>
  );
}

/** The one select of the panel: the same shape wherever a menu offers one. */
export function MenuSelect<V extends string>({
  label,
  value,
  options,
  disabled,
  onChange,
}: {
  label: string;
  value: V;
  options: readonly { value: V; label: string }[];
  disabled?: boolean;
  onChange: (value: V) => void;
}) {
  return (
    <label className="flex items-center justify-between gap-2 text-sm">
      <span className="text-xs font-semibold text-ink-muted">{label}</span>
      <select
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value as V)}
        className="min-w-[8rem] rounded-lg border border-line bg-surface-raised px-2 py-1 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:opacity-50"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  );
}
