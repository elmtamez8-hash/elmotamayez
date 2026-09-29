"use client";

import { useRef, type ComponentType, type ReactNode } from "react";

import { SearchIcon, XIcon, type IconProps } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * The bar above a staff list: a search box, a set of filter chips, and a line
 * saying how many records are showing. Every part is optional; the bar is one
 * shape on every screen that filters. Match text with `matchesSearch()` from
 * `lib/search-text.ts` — it folds the Arabic spellings a keyboard disagrees on.
 *
 * ⚠️ CHIPS, NOT `Tabs`. A tab strip owns panels (`tabpanel`, `?tab=`, roving
 * focus); a filter narrows ONE list in place. Using `Tabs` for a filter gives a
 * screen reader a tab with no panel. The chips are toggle buttons
 * (`aria-pressed`) in a named group, exactly one pressed.
 *
 * ⚠️ THE CHIPS WRAP, THEY NEVER SCROLL — the measured reason is on `Tabs`: a
 * chip behind a sideways scrollbar is a filter nobody finds on a phone.
 *
 * No free-form `className`, the kit's rule.
 */

export type FilterOption = {
  /** Stable — never the Arabic label. */
  key: string;
  label: string;
  /** How many records this chip would show; omitted when the server does not say. */
  count?: number;
  Icon?: ComponentType<IconProps>;
};

type SearchConfig = {
  id: string;
  /** Required: the box's accessible name. Visually hidden; the placeholder is not a label. */
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
};

type FiltersConfig = {
  /** What the chips choose between («حالة الإعلان») — the group's accessible name. */
  label: string;
  options: FilterOption[];
  value: string;
  onChange: (key: string) => void;
};

export function FilterBar({
  search,
  filters,
  summary,
}: {
  search?: SearchConfig;
  filters?: FiltersConfig;
  /** «١٢ إعلاناً» — built with `counted()`, announced politely as it changes. */
  summary?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-3 rounded-3xl border border-line bg-surface-raised p-3 sm:p-4 lg:flex-row lg:items-center">
      {search !== undefined && <SearchBox {...search} />}
      {filters !== undefined && <FilterChips {...filters} />}
      {summary !== undefined && (
        <p aria-live="polite" className="text-sm text-ink-muted lg:ms-auto">
          {summary}
        </p>
      )}
    </div>
  );
}

function SearchBox({ id, label, value, onChange, placeholder }: SearchConfig) {
  const input = useRef<HTMLInputElement>(null);

  return (
    <div className="relative w-full lg:max-w-xs">
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <SearchIcon
        className="pointer-events-none absolute start-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted"
        aria-hidden="true"
      />
      <input
        ref={input}
        id={id}
        name={id}
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        autoComplete="off"
        /* The browser's own «×» is hidden: two clear buttons for one job, and
           only one of them is ours to name. Same call as `PasswordField`'s eye. */
        className="w-full rounded-full border border-line bg-surface py-2 pe-10 ps-10 text-sm text-ink transition placeholder:text-ink-muted hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary [&::-webkit-search-cancel-button]:appearance-none"
      />
      {value !== "" && (
        <button
          type="button"
          aria-label="مسح البحث"
          onClick={() => {
            onChange("");
            input.current?.focus();
          }}
          className="absolute end-1.5 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-full text-ink-muted transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        >
          <XIcon className="h-4 w-4" aria-hidden="true" />
        </button>
      )}
    </div>
  );
}

function FilterChips({ label, options, value, onChange }: FiltersConfig) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap gap-2">
      {options.map((option) => {
        const pressed = option.key === value;

        return (
          <button
            key={option.key}
            type="button"
            aria-pressed={pressed}
            onClick={() => onChange(option.key)}
            className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition duration-200 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
              pressed
                ? "border-primary bg-primary text-white"
                : "border-line bg-surface-raised text-ink hover:border-primary/40 hover:bg-primary-soft hover:text-primary-ink"
            }`}
          >
            {option.Icon !== undefined && <option.Icon className="h-4 w-4" aria-hidden="true" />}
            {option.label}
            {/* A real space before the count, for the reason `Tabs` gives: the
                accessible name would otherwise read «المنشورة3». */}
            {option.count !== undefined && (
              <>
                {" "}
                <span className={`tabular-nums text-xs ${pressed ? "text-white" : "text-ink-muted"}`}>
                  {arabicNumber(option.count)}
                </span>
              </>
            )}
          </button>
        );
      })}
    </div>
  );
}
