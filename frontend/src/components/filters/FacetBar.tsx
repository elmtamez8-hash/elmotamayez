"use client";

import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";

/**
 * A row of «narrow this list» selects, driven by options the SERVER derived.
 *
 * ⚠️ AN EMPTY FACET IS NOT DRAWN, AND THAT IS THE WHOLE POINT OF THE COMPONENT.
 * A `<select>` labelled «المدرّس» with nothing in it is a question with no
 * answers, which reads as a page that failed to load — and it is exactly what
 * `/practice` shipped for two specs, because it filled its only filter from a
 * teacher-only endpoint that answers a student `403`. A student with one teacher
 * needs no teacher control either: one option is a control that cannot change
 * anything.
 *
 * ⚠️ AND NOTHING HERE ASSEMBLES ITS OWN OPTIONS. Every list arrives from an
 * endpoint that derived it from the query being filtered, so an offered value
 * always narrows to something and a value the server would refuse is never
 * offered. Spec 009's leaderboard picker was built the other way — from the
 * nearest list to hand — and offered scopes the API answered `403`.
 *
 * State lives in the page, not the URL: nobody asked to share a filtered
 * homework list, and `?tab=`-style plumbing for a control that resets on every
 * visit is machinery with no reader.
 */

export interface FacetOption {
  uuid: string;
  label: string;
}

export interface Facet {
  /** The filter key this select writes. */
  key: string;
  label: string;
  /** Shown as the «all of them» row, and the value is the empty string. */
  placeholder: string;
  options: FacetOption[];
}

export function FacetBar({
  facets,
  values,
  onChange,
  onReset,
}: {
  facets: Facet[];
  values: Record<string, string>;
  onChange: (key: string, value: string) => void;
  onReset: () => void;
}) {
  /*
    ⚠️ EMPTY IS HIDDEN; ONE OPTION IS NOT. This was `> 1` for a day and it deleted
    the whole bar on ordinary data: a student with one teacher and one course got
    every facet dropped, so the page answered «فين الفلاتر» — the controls were
    working and invisible. A single option still names what the reader is looking
    at and still narrows a list that will gain rows; the defect actually worth
    guarding is a `<select>` with NOTHING in it, which is a question with no
    answers and reads as a page that failed to load. That one stays hidden.
  */
  const usable = facets.filter((facet) => facet.options.length > 0);

  if (usable.length === 0) return null;

  const active = usable.some((facet) => (values[facet.key] ?? "") !== "");

  return (
    <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-surface p-4">
      {usable.map((facet) => (
        <div key={facet.key} className="min-w-44 grow sm:grow-0">
          <SelectField
            id={`facet-${facet.key}`}
            label={facet.label}
            value={values[facet.key] ?? ""}
            onChange={(value) => onChange(facet.key, value)}
            placeholder={facet.placeholder}
            options={facet.options.map((option) => ({ value: option.uuid, label: option.label }))}
          />
        </div>
      ))}

      {/*
        Only once something is narrowed. A permanent «امسح التصفية» beside an
        untouched bar is a button that does nothing, on the row where every other
        control does something.
      */}
      {active && (
        <Button variant="ghost" size="sm" onClick={onReset}>
          امسح التصفية
        </Button>
      )}
    </div>
  );
}
