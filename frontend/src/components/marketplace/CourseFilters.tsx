"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useTransition } from "react";
import type { Taxonomy } from "@/lib/public-api";
import { Select } from "@/components/ui/Field";

/**
 * Horizontal filter bar for the courses page.
 *
 * Same contract as TeacherFilters: state lives in the URL so a filtered result is
 * shareable and the page can stay a Server Component (FR-051, SC-015).
 */
const TYPES = [
  { value: "individual", label: "فردي" },
  { value: "group", label: "جماعي" },
  { value: "recorded", label: "مسجّل" },
];

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
    "w-full rounded-xl border border-line bg-surface-raised px-3 py-2.5 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

  const hasFilters = ["subject", "grade_level", "type"].some(
    (key) => params.get(key),
  );

  return (
    <div
      aria-busy={pending}
      className="mb-8 rounded-2xl border border-line bg-surface-raised p-5"
    >
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div>
          <label htmlFor="f-subject" className="mb-1.5 block text-sm font-semibold text-ink">
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
                {subject.name_ar}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-grade" className="mb-1.5 block text-sm font-semibold text-ink">
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
                {level.name_ar}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-type" className="mb-1.5 block text-sm font-semibold text-ink">
            نوع الكورس
          </label>
          <Select
            id="f-type"
            value={params.get("type") ?? ""}
            onChange={(event) => update("type", event.target.value)}
            className={field}
          >
            <option value="">كل الأنواع</option>
            {TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <label htmlFor="f-sort" className="mb-1.5 block text-sm font-semibold text-ink">
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
          className="mt-4 text-sm font-semibold text-primary-ink underline"
        >
          إزالة كل الفلاتر
        </button>
      )}
    </div>
  );
}
