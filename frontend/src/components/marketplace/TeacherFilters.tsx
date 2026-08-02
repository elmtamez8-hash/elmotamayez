"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useTransition } from "react";
import type { Taxonomy } from "@/lib/public-api";

/**
 * Filter state lives in the URL, not in component state.
 *
 * That is what makes a filtered result shareable and reproducible (FR-051,
 * SC-015), and it lets the page stay a Server Component: this control only
 * rewrites the query string and lets the server re-render.
 */
const SORTS = [
  { value: "rating_desc", label: "الأعلى تقييمًا" },
  { value: "trust_desc", label: "الأعلى ثقة" },
  { value: "price_asc", label: "الأقل سعرًا" },
];

const LANGUAGES = [
  { value: "ar", label: "العربية" },
  { value: "en", label: "الإنجليزية" },
  { value: "fr", label: "الفرنسية" },
];

export function TeacherFilters({
  subjects,
  gradeLevels,
  // The page renders this twice — a mobile drawer and a desktop sidebar — so ids
  // must be namespaced. Duplicate ids break every label/control association on
  // the page, not just the second copy.
  idPrefix,
}: {
  subjects: Taxonomy[];
  gradeLevels: Taxonomy[];
  idPrefix: string;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [pending, startTransition] = useTransition();

  const id = (name: string) => `${idPrefix}-${name}`;

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params.toString());

    if (value === "") next.delete(key);
    else next.set(key, value);

    // Any filter change resets to page 1; keeping the old page number is how a
    // narrowed search lands on an empty page that looks like "no results".
    next.delete("page");

    startTransition(() => router.push(`${pathname}?${next.toString()}`));
  };

  const active = Array.from(params.entries()).filter(
    ([key]) => !["page", "sort"].includes(key),
  );

  const field =
    "w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary dark:bg-transparent";

  return (
    <div aria-busy={pending} className="space-y-5">
      <div>
        <label htmlFor={id("q")} className="mb-1.5 block text-sm font-semibold text-ink">
          بحث بالاسم
        </label>
        <input
          id={id("q")}
          type="search"
          defaultValue={params.get("q") ?? ""}
          onChange={(event) => update("q", event.target.value)}
          placeholder="اسم المدرّس"
          className={field}
        />
      </div>

      <div>
        <label htmlFor={id("subject")} className="mb-1.5 block text-sm font-semibold text-ink">
          المادة
        </label>
        <select
          id={id("subject")}
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
        </select>
      </div>

      <div>
        <label htmlFor={id("grade_level")} className="mb-1.5 block text-sm font-semibold text-ink">
          المرحلة الدراسية
        </label>
        <select
          id={id("grade_level")}
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
        </select>
      </div>

      <fieldset>
        <legend className="mb-1.5 text-sm font-semibold text-ink">السعر لكل حصة</legend>
        <div className="flex gap-2">
          <label className="flex-1">
            <span className="sr-only">الحد الأدنى للسعر</span>
            <input
              type="number"
              min={0}
              placeholder="من"
              defaultValue={params.get("price_min") ?? ""}
              onChange={(event) => update("price_min", event.target.value)}
              className={field}
            />
          </label>
          <label className="flex-1">
            <span className="sr-only">الحد الأعلى للسعر</span>
            <input
              type="number"
              min={0}
              placeholder="إلى"
              defaultValue={params.get("price_max") ?? ""}
              onChange={(event) => update("price_max", event.target.value)}
              className={field}
            />
          </label>
        </div>
      </fieldset>

      <div>
        <label htmlFor={id("min_rating")} className="mb-1.5 block text-sm font-semibold text-ink">
          الحد الأدنى للتقييم
        </label>
        <select
          id={id("min_rating")}
          value={params.get("min_rating") ?? ""}
          onChange={(event) => update("min_rating", event.target.value)}
          className={field}
        >
          <option value="">أي تقييم</option>
          {[4.5, 4, 3.5, 3].map((rating) => (
            <option key={rating} value={rating}>
              {rating} نجوم فأكثر
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor={id("min_trust_score")} className="mb-1.5 block text-sm font-semibold text-ink">
          الحد الأدنى لدرجة الثقة
        </label>
        <select
          id={id("min_trust_score")}
          value={params.get("min_trust_score") ?? ""}
          onChange={(event) => update("min_trust_score", event.target.value)}
          className={field}
        >
          <option value="">أي درجة</option>
          {[80, 60, 40].map((score) => (
            <option key={score} value={score}>
              {score} فأكثر
            </option>
          ))}
        </select>
        {/* Said out loud, because the filter silently drops teachers the visitor
            might otherwise expect to see (FR-026). */}
        <p className="mt-1 text-xs text-ink-muted">
          يستبعد هذا الفلتر المدرّسين الجدد الذين لم تُحتسب درجتهم بعد.
        </p>
      </div>

      <div>
        <label htmlFor={id("language")} className="mb-1.5 block text-sm font-semibold text-ink">
          لغة التدريس
        </label>
        <select
          id={id("language")}
          value={params.get("language") ?? ""}
          onChange={(event) => update("language", event.target.value)}
          className={field}
        >
          <option value="">كل اللغات</option>
          {LANGUAGES.map((lang) => (
            <option key={lang.value} value={lang.value}>
              {lang.label}
            </option>
          ))}
        </select>
      </div>

      <label className="flex items-center gap-2.5 text-sm font-medium text-ink">
        <input
          type="checkbox"
          checked={params.get("available_now") === "1"}
          onChange={(event) => update("available_now", event.target.checked ? "1" : "")}
          className="h-4 w-4 rounded border-line text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        />
        متاح الآن
      </label>

      <div>
        <label htmlFor={id("sort")} className="mb-1.5 block text-sm font-semibold text-ink">
          ترتيب حسب
        </label>
        <select
          id={id("sort")}
          value={params.get("sort") ?? "rating_desc"}
          onChange={(event) => update("sort", event.target.value)}
          className={field}
        >
          {SORTS.map((sort) => (
            <option key={sort.value} value={sort.value}>
              {sort.label}
            </option>
          ))}
        </select>
      </div>

      {active.length > 0 && (
        <div>
          <p className="mb-2 text-sm font-semibold text-ink">الفلاتر المطبّقة</p>
          <ul className="flex flex-wrap gap-2">
            {active.map(([key, value]) => (
              <li key={`${key}-${value}`}>
                <button
                  type="button"
                  onClick={() => update(key, "")}
                  className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-xs font-medium text-primary-ink transition hover:brightness-95"
                >
                  {value}
                  <span aria-hidden="true">×</span>
                  <span className="sr-only">إزالة الفلتر</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
