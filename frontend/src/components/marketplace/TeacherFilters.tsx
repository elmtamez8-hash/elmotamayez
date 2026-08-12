"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useTransition } from "react";
import { CloseIcon } from "@/components/icons";
import type { Taxonomy } from "@/lib/public-api";

/**
 * Filter state lives in the URL, not in component state.
 *
 * That is what makes a filtered result shareable and reproducible (FR-051,
 * SC-015), and it lets the page stay a Server Component: this control only
 * rewrites the query string and lets the server re-render.
 *
 * ⚠️ SUBJECT IS NOT HERE. It moved to `SubjectPills` — real links, above the
 * results — because it is the axis a parent starts from and it was buried as
 * the second of six identical dropdowns. What is left is refinement: narrow the
 * set you already chose to look at.
 */
const SORTS = [
  { value: "rating_desc", label: "الأعلى تقييمًا" },
  { value: "trust_desc", label: "الأعلى ثقة" },
];

const LANGUAGES = [
  { value: "ar", label: "العربية" },
  { value: "en", label: "الإنجليزية" },
  { value: "fr", label: "الفرنسية" },
];

const RATINGS = [4.5, 4, 3.5, 3];
const TRUST_SCORES = [80, 60, 40];

const ar = (value: number) => value.toLocaleString("ar-QA");

export function TeacherFilters({
  gradeLevels,
  // The page renders this twice — a mobile drawer and a desktop bar — so ids
  // must be namespaced. Duplicate ids break every label/control association on
  // the page, not just the second copy.
  idPrefix,
}: {
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

    startTransition(() => router.push(`${pathname}?${next.toString()}`, { scroll: false }));
  };

  const field =
    "w-full rounded-full border border-line bg-surface-raised px-4 py-2.5 text-sm text-ink " +
    "transition duration-200 hover:border-primary/50 " +
    "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary";

  const labelClass = "mb-1.5 block text-xs font-semibold text-ink-muted";

  return (
    <div
      aria-busy={pending}
      className="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(4,1fr)_auto] lg:items-end"
    >
      <div>
        <label htmlFor={id("q")} className={labelClass}>
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
        <label htmlFor={id("grade_level")} className={labelClass}>
          المرحلة
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

      <div>
        <label htmlFor={id("min_rating")} className={labelClass}>
          التقييم
        </label>
        <select
          id={id("min_rating")}
          value={params.get("min_rating") ?? ""}
          onChange={(event) => update("min_rating", event.target.value)}
          className={field}
        >
          <option value="">أي تقييم</option>
          {RATINGS.map((rating) => (
            <option key={rating} value={rating}>
              {ar(rating)} فأكثر
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor={id("min_trust_score")} className={labelClass}>
          درجة الثقة
        </label>
        <select
          id={id("min_trust_score")}
          value={params.get("min_trust_score") ?? ""}
          onChange={(event) => update("min_trust_score", event.target.value)}
          className={field}
        >
          <option value="">أي درجة</option>
          {TRUST_SCORES.map((score) => (
            <option key={score} value={score}>
              {ar(score)} فأكثر
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor={id("language")} className={labelClass}>
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

      <div className="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-1 lg:pb-2.5">
        <label className="flex items-center gap-2.5 text-sm font-medium text-ink">
          <input
            type="checkbox"
            checked={params.get("available_now") === "1"}
            onChange={(event) => update("available_now", event.target.checked ? "1" : "")}
            className="h-4 w-4 rounded border-line text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
          />
          متاح الآن
        </label>

        <label htmlFor={id("sort")} className="sr-only">
          ترتيب حسب
        </label>
        <select
          id={id("sort")}
          value={params.get("sort") ?? "rating_desc"}
          onChange={(event) => update("sort", event.target.value)}
          className="rounded-full border border-line bg-surface-raised px-4 py-2.5 text-sm text-ink transition duration-200 hover:border-primary/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
        >
          {SORTS.map((sort) => (
            <option key={sort.value} value={sort.value}>
              {sort.label}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

/**
 * What is currently narrowing the list, and how to undo it.
 *
 * ⚠️ Every chip used to print the RAW QUERY VALUE. «متاح الآن» read as `1`,
 * a subject read as `math`, a trust filter read as `60` — three chips a visitor
 * could not map back to the control that set them, on the one component whose
 * whole job is to say what is currently applied. The label is built from the
 * same lists the controls are built from, so the two cannot drift.
 */
export function ActiveFilters({
  subjects,
  gradeLevels,
}: {
  subjects: Taxonomy[];
  gradeLevels: Taxonomy[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  const nameOf = (list: Taxonomy[], slug: string) =>
    list.find((item) => item.slug === slug)?.name_ar ?? slug;

  const describe = (key: string, value: string): string | null => {
    switch (key) {
      case "subject":
        return nameOf(subjects, value);
      case "grade_level":
        return nameOf(gradeLevels, value);
      case "min_rating":
        return `${ar(Number(value))} نجوم فأكثر`;
      case "min_trust_score":
        return `ثقة ${ar(Number(value))} فأكثر`;
      case "language":
        return LANGUAGES.find((lang) => lang.value === value)?.label ?? value;
      case "available_now":
        return value === "1" ? "متاح الآن" : null;
      case "q":
        return `بحث: ${value}`;
      default:
        // `page` and `sort` are not filters, and an unknown key is a stale
        // bookmark — printing it would put `price_min` back on the screen the
        // page's own FILTER_KEYS list exists to keep it off.
        return null;
    }
  };

  const active = Array.from(params.entries())
    .map(([key, value]) => ({ key, value, label: describe(key, value) }))
    .filter((entry): entry is { key: string; value: string; label: string } =>
      entry.label !== null,
    );

  if (active.length === 0) return null;

  const remove = (key: string) => {
    const next = new URLSearchParams(params.toString());

    next.delete(key);
    next.delete("page");

    const query = next.toString();

    router.push(query === "" ? pathname : `${pathname}?${query}`, { scroll: false });
  };

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-xs font-semibold text-ink-muted">المطبَّق:</span>

      <ul className="flex flex-wrap gap-2">
        {active.map((entry) => (
          <li key={`${entry.key}-${entry.value}`}>
            <button
              type="button"
              onClick={() => remove(entry.key)}
              className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1.5 text-xs font-semibold text-primary-ink transition duration-200 ease-out hover:brightness-95 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {entry.label}
              <CloseIcon className="h-3 w-3" aria-hidden="true" />
              <span className="sr-only">إزالة الفلتر</span>
            </button>
          </li>
        ))}
      </ul>

      {active.length > 1 && (
        <button
          type="button"
          onClick={() => router.push(pathname, { scroll: false })}
          className="text-xs font-semibold text-ink-muted underline-offset-4 transition hover:text-primary-ink hover:underline"
        >
          مسح الكل
        </button>
      )}
    </div>
  );
}
