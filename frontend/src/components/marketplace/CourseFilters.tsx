"use client";

import { useState } from "react";
import type { Taxonomy } from "@/lib/public-api";
import { Select } from "@/components/ui/Field";
import { CloseIcon } from "@/components/icons";
import { COURSE_TYPES, courseTypeLabel } from "@/lib/labels";
import {
  chip,
  FilterSheet,
  Option,
  pill,
  rankSubjects,
  SheetButton,
  SheetGroup,
  SubjectChips,
  useFilterUrl,
} from "./filter-kit";

/**
 * The courses page's filters, in the teachers page's shape (owner, 2026-10-26):
 * the course type and the subject are one-tap chips on every width; the stage
 * and the sort are compact selects on a laptop and a bottom sheet on a phone,
 * so four stacked dropdowns no longer push the first course below the fold.
 *
 * The subjects here are NOT scoped to the stage (the page fetches them all), so
 * changing the stage keeps the subject.
 */
const SORTS = [
  { value: "popular", label: "الأكثر طلباً" },
  { value: "newest", label: "الأحدث" },
];

export function CourseFilters({
  subjects,
  gradeLevels,
}: {
  subjects: Taxonomy[];
  gradeLevels: Taxonomy[];
}) {
  const { get, pending, update, clear } = useFilterUrl();
  const [sheetOpen, setSheetOpen] = useState(false);

  const type = get("type");
  const stage = get("grade_level");
  const subject = get("subject");
  const sort = get("sort") || "popular";
  const hasFilters = type !== "" || stage !== "" || subject !== "";

  return (
    <div
      aria-busy={pending}
      className="mb-10 rounded-3xl border border-line bg-surface-raised p-4 shadow-lg shadow-primary/10 transition-opacity aria-busy:opacity-70 motion-reduce:transition-none sm:p-5"
    >
      {/* Row 1 — the kind of course, then (laptop) the stage and the sort;
          (phone) the sheet's button. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label="نوع الكورس" className="flex flex-1 flex-wrap items-center gap-2">
          <button type="button" aria-pressed={type === ""} onClick={() => update("type", "")} className={chip(type === "")}>
            كل الأنواع
          </button>
          {COURSE_TYPES.map((item) => (
            <button
              key={item.value}
              type="button"
              aria-pressed={type === item.value}
              onClick={() => update("type", type === item.value ? "" : item.value)}
              className={chip(type === item.value)}
            >
              {/* الاسمُ وحدَه: التوضيحُ بعد الشرطة للمدرّس وهو يختار، لا للزائر وهو يفلتر. */}
              {courseTypeLabel(item.value)}
            </button>
          ))}
        </div>

        <label htmlFor="course-stage" className="sr-only">المرحلة الدراسية</label>
        <div className="hidden lg:block">
          <Select id="course-stage" value={stage} onChange={(event) => update("grade_level", event.target.value)} className={pill}>
            <option value="">كل المراحل</option>
            {gradeLevels.map((level) => (
              <option key={level.slug} value={level.slug}>{level.name}</option>
            ))}
          </Select>
        </div>

        <label htmlFor="course-sort" className="sr-only">ترتيب حسب</label>
        <div className="hidden lg:block">
          <Select id="course-sort" value={sort} onChange={(event) => update("sort", event.target.value)} className={pill}>
            {SORTS.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </Select>
        </div>

        <SheetButton count={stage === "" ? 0 : 1} onClick={() => setSheetOpen(true)} />
      </div>

      {/* Row 2 — the subject. */}
      <SubjectChips id="course" subjects={subjects} subject={subject} allLabel="كل المواد" onPick={(slug) => update("subject", slug)} />

      {hasFilters && (
        <button
          type="button"
          onClick={clear}
          className="mt-4 hidden items-center gap-1.5 text-sm font-semibold text-ink-muted underline-offset-4 transition hover:text-primary-ink hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:inline-flex"
        >
          <CloseIcon className="h-4 w-4" />
          إزالة كل الفلاتر
        </button>
      )}

      <FilterSheet id="course-sheet" open={sheetOpen} onClose={() => setSheetOpen(false)} onClear={clear}>
        <SheetGroup title="المرحلة الدراسية">
          <Option pressed={stage === ""} onClick={() => update("grade_level", "")}>كل المراحل</Option>
          {gradeLevels.map((level) => (
            <Option key={level.slug} pressed={stage === level.slug} onClick={() => update("grade_level", level.slug)}>
              {level.name}
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="المادة">
          <Option pressed={subject === ""} onClick={() => update("subject", "")}>كل المواد</Option>
          {rankSubjects(subjects).map((item) => (
            <Option key={item.slug} pressed={subject === item.slug} onClick={() => update("subject", item.slug)}>
              {item.name}
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="الترتيب">
          {SORTS.map((item) => (
            <Option key={item.value} pressed={sort === item.value} onClick={() => update("sort", item.value)}>
              {item.label}
            </Option>
          ))}
        </SheetGroup>
      </FilterSheet>
    </div>
  );
}
