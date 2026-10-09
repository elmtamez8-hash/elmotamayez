"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useTransition } from "react";
import type { Taxonomy } from "@/lib/public-api";
import { Select } from "@/components/ui/Field";
import { COURSE_TYPES, courseTypeLabel } from "@/lib/labels";
import { CloseIcon, SearchIcon } from "@/components/icons";

/**
 * Horizontal filter bar for the courses page.
 *
 * Same contract as TeacherFilters: state lives in the URL so a filtered result is
 * shareable and the page can stay a Server Component (FR-051, SC-015).
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
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [pending, startTransition] = useTransition();

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params.toString());

    if (value === "") next.delete(key);
    else next.set(key, value);

    // A narrowed search must not land on page 4 of a 2-page result and read as
    // "no courses found".
    next.delete("page");

    startTransition(() => router.push(`${pathname}?${next.toString()}`));
  };

  const field =
    "w-full rounded-2xl border border-line bg-surface px-3.5 py-3 text-sm font-semibold text-ink transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none";

  const hasFilters = ["subject", "grade_level", "type"].some(
    (key) => params.get(key),
  );

  return (
    <div
      aria-busy={pending}
      className="mb-10 flex flex-col gap-5 rounded-3xl border border-line bg-surface-raised p-5 shadow-lg shadow-primary/5 transition-opacity aria-busy:opacity-70 motion-reduce:transition-none sm:p-6 lg:flex-row lg:items-end"
    >
      {/* علامةٌ لا عنوان: الحقولُ الأربعةُ تحملُ أسماءَها، والبلاطةُ تقولُ
          «هنا تُضيِّقُ النتائج» بلا كلمةٍ فوقَها. */}
      <span
        aria-hidden="true"
        className="hidden h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-white shadow-md shadow-primary/20 lg:grid"
      >
        <SearchIcon className="h-6 w-6" />
      </span>

      <div className="grid flex-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label htmlFor="f-subject" className="mb-1.5 block text-sm font-bold text-ink">
            المادة
          </label>
          <Select
            id="f-subject"
            value={params.get("subject") ?? ""}
            onChange={(event) => update("subject", event.target.value)}
            className={field}
          >
            <option value="">كل المواد</option>
            {subjects.map((subject) => (
              <option key={subject.slug} value={subject.slug}>
                {subject.name}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-grade" className="mb-1.5 block text-sm font-bold text-ink">
            المرحلة الدراسية
          </label>
          <Select
            id="f-grade"
            value={params.get("grade_level") ?? ""}
            onChange={(event) => update("grade_level", event.target.value)}
            className={field}
          >
            <option value="">كل المراحل</option>
            {gradeLevels.map((level) => (
              <option key={level.slug} value={level.slug}>
                {level.name}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-type" className="mb-1.5 block text-sm font-bold text-ink">
            نوع الكورس
          </label>
          <Select
            id="f-type"
            value={params.get("type") ?? ""}
            onChange={(event) => update("type", event.target.value)}
            className={field}
          >
            <option value="">كل الأنواع</option>
            {COURSE_TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {/* الفلتر يعرض الاسم وحده: التوضيحُ بعد الشرطة للمدرّس وهو يختار، لا للزائر وهو يفلتر. */}
                {courseTypeLabel(type.value)}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-sort" className="mb-1.5 block text-sm font-bold text-ink">
            ترتيب حسب
          </label>
          <Select
            id="f-sort"
            value={params.get("sort") ?? "popular"}
            onChange={(event) => update("sort", event.target.value)}
            className={field}
          >
            {SORTS.map((sort) => (
              <option key={sort.value} value={sort.value}>
                {sort.label}
              </option>
            ))}
          </Select>
        </div>
      </div>

      {hasFilters && (
        <button
          type="button"
          onClick={() => startTransition(() => router.push(pathname))}
          className="inline-flex w-fit shrink-0 items-center gap-1.5 self-start rounded-full bg-primary-soft px-4 py-2.5 text-sm font-extrabold text-primary-ink transition hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none lg:self-end"
        >
          <CloseIcon className="h-4 w-4" />
          إزالة كل الفلاتر
        </button>
      )}
    </div>
  );
}
