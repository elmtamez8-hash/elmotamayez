"use client";

import { SelectField, TextField } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import type { FilterOption, MistakeFilterOptions, MistakeFilters } from "@/lib/mistakes";

/**
 * Narrowing the mistake notebook — by teacher, subject, exam, concept or date.
 *
 * ⚠️ EVERY OPTION COMES FROM THE SERVER, AND THE SERVER DERIVES IT FROM THE
 * NOTEBOOK'S OWN QUERY. Nothing here is assembled from another list: the reader
 * is offered a teacher only if they have a standing mistake with them, a course
 * only if one of its lessons holds one, an exam only if that is where they last
 * got something wrong. Spec 009 built a leaderboard picker the other way — from
 * the nearest list to hand — and it offered boards the API answered `403` while
 * hiding boards it allowed. The rule that came out of it is that a picker is
 * derived from the authoriser's own predicate.
 *
 * ⚠️ AND A FACET WITH NOTHING IN IT IS NOT DRAWN. A student with one teacher
 * needs no teacher control; an empty `<select>` labelled «المدرّس» is a question
 * with no answers, which reads as a page that failed to load.
 *
 * State lives here and in the page — not in the URL. Nobody asked to share a
 * filtered notebook, and `?tab=`-style plumbing for a control that resets on
 * every visit is machinery with no reader.
 */

/**
 * ⚠️ NOT `keyof MistakeFilterOptions`. That type carries `has_standing` too — a
 * boolean answering a different question — and a facet list typed by it would
 * happily let somebody render a `<select>` over `true`.
 */
type FacetKey = "teachers" | "courses" | "exams" | "concepts";

const FACETS: Array<{ key: keyof MistakeFilters; facet: FacetKey; label: string; placeholder: string }> = [
  { key: "teacher", facet: "teachers", label: "المدرّس", placeholder: "كلّ المدرّسين" },
  { key: "course", facet: "courses", label: "المادّة", placeholder: "كلّ المواد" },
  { key: "exam", facet: "exams", label: "الاختبار", placeholder: "كلّ الاختبارات" },
  { key: "concept", facet: "concepts", label: "الفكرة", placeholder: "كلّ الأفكار" },
];

function asOptions(rows: FilterOption[]) {
  return rows.map((row) => ({ value: row.uuid, label: row.label }));
}

export function MistakeFilterBar({
  options,
  value,
  onChange,
}: {
  options: MistakeFilterOptions | null;
  value: MistakeFilters;
  onChange: (next: MistakeFilters) => void;
}) {
  if (options === null) return null;

  const set = (key: keyof MistakeFilters, next: string) =>
    onChange({ ...value, [key]: next === "" ? undefined : next });

  const shown = FACETS.filter((facet) => options[facet.facet].length > 0);

  // Everything except the resolved toggle, which lives beside the list and is
  // not a narrowing of the same kind.
  const active = Object.entries(value).filter(
    ([key, entry]) => key !== "include_resolved" && entry !== undefined && entry !== "",
  ).length;

  if (shown.length === 0 && active === 0) return null;

  return (
    <section
      aria-label="تصفية دفتر الأخطاء"
      className="rounded-2xl border border-line bg-surface-raised p-4"
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map((facet) => (
          <SelectField
            key={facet.key}
            id={`mistake-${facet.key}`}
            label={facet.label}
            value={(value[facet.key] as string | undefined) ?? ""}
            onChange={(next) => set(facet.key, next)}
            placeholder={facet.placeholder}
            options={asOptions(options[facet.facet])}
          />
        ))}

        {/*
          ⚠️ THE PERIOD FILTERS THE MISTAKE, NOT THE ANSWER SET — the server says
          so, and it matters to what these two controls mean: a question missed
          in January and fixed in March is absent from a January window, because
          it is no longer a mistake. They are bare dates; the server treats the
          upper bound as inclusive of its whole last day.
        */}
        <TextField
          id="mistake-from"
          label="من تاريخ"
          type="date"
          value={value.from ?? ""}
          onChange={(next) => set("from", next)}
        />
        <TextField
          id="mistake-to"
          label="إلى تاريخ"
          type="date"
          value={value.to ?? ""}
          onChange={(next) => set("to", next)}
        />
      </div>

      {active > 0 && (
        <div className="mt-3">
          <Button
            variant="ghost"
            size="sm"
            onClick={() =>
              // The resolved toggle is deliberately kept: it is a view of the
              // same list, not one of the narrowings this button clears.
              onChange({ include_resolved: value.include_resolved })
            }
          >
            امسح التصفية
          </Button>
        </div>
      )}
    </section>
  );
}
