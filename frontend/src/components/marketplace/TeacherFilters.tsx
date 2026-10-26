"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useState } from "react";
import { CloseIcon, SearchIcon, StarIcon } from "@/components/icons";
import { Select } from "@/components/ui/Field";
import type { Taxonomy } from "@/lib/public-api";
import { arabicNumber } from "@/lib/numerals";
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
 * ⚠️ THE SUBJECT LIST DEPENDS ON THE STAGE, and the server produces it. The
 * page fetches `/marketplace/subjects?grade_level=…`, so choosing «المرحلة
 * الابتدائية» leaves two subjects in the control instead of eleven. That is why
 * the stage sits BEFORE the subject here, and why changing it clears the
 * subject (`stageScopesSubjects`).
 *
 * THE SHAPE (owner, 2026-10-23). The subject is how a parent starts («I need
 * someone for physics»), so the most-taught subjects are one-tap chips under
 * the search. The two switches are toggle chips on every width. On a laptop the
 * remaining filters are one row of compact selects; on a phone they live in a
 * bottom sheet behind «فلاتر», as tap-able options rather than six stacked
 * dropdowns. The shared pieces are in `filter-kit`.
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

/** The filters that live in the sheet on a phone — counted on its button. */
const SHEET_KEYS = ["grade_level", "min_rating", "min_trust_score", "language"] as const;

const ar = (value: number) => arabicNumber(value);

export function TeacherFilters({
  subjects,
  gradeLevels,
}: {
  /** Already scoped to the chosen stage by the page that fetched them. */
  subjects: Taxonomy[];
  gradeLevels: Taxonomy[];
}) {
  const { get, pending, update, clear } = useFilterUrl({ stageScopesSubjects: true });
  const [sheetOpen, setSheetOpen] = useState(false);

  const subject = get("subject");
  const stage = get("grade_level");
  const allSubjects = stage ? "كل مواد المرحلة" : "كل المواد";
  const sheetCount = SHEET_KEYS.filter((key) => get(key) !== "").length;

  return (
    <div
      aria-busy={pending}
      className="rounded-3xl border border-line bg-surface-raised p-4 shadow-lg shadow-primary/10 sm:p-5"
    >
      {/* Row 1 — the name search, then (laptop) the stage that narrows the
          subjects below it and the sort; (phone) the sheet's button. */}
      <div className="flex items-center gap-2">
        <label htmlFor="teacher-q" className="sr-only">بحث باسم المدرّس</label>
        <div className="relative min-w-0 flex-1">
          <SearchIcon className="pointer-events-none absolute start-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
          <input
            id="teacher-q"
            type="search"
            defaultValue={get("q")}
            onChange={(event) => update("q", event.target.value)}
            placeholder="ابحث باسم المدرّس"
            className={`w-full ps-10 ${pill}`}
          />
        </div>

        <label htmlFor="teacher-stage" className="sr-only">المرحلة</label>
        <div className="hidden lg:block">
          <Select id="teacher-stage" value={stage} onChange={(event) => update("grade_level", event.target.value)} className={pill}>
            <option value="">كل المراحل</option>
            {gradeLevels.map((level) => (
              <option key={level.slug} value={level.slug}>{level.name}</option>
            ))}
          </Select>
        </div>

        <label htmlFor="teacher-sort" className="sr-only">ترتيب حسب</label>
        <div className="hidden lg:block">
          <Select id="teacher-sort" value={get("sort") || "rating_desc"} onChange={(event) => update("sort", event.target.value)} className={pill}>
            {SORTS.map((sort) => (
              <option key={sort.value} value={sort.value}>{sort.label}</option>
            ))}
          </Select>
        </div>

        <SheetButton count={sheetCount} onClick={() => setSheetOpen(true)} />
      </div>

      {/* Row 2 — the subject, the question a visitor arrives with. */}
      <SubjectChips id="teacher" subjects={subjects} subject={subject} allLabel={allSubjects} onPick={(slug) => update("subject", slug)} />

      {/* Row 3 — the switches on every width; the finer selects on a laptop
          only (a phone has them in the sheet). */}
      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
        <button
          type="button"
          aria-pressed={get("available_now") === "1"}
          onClick={() => update("available_now", get("available_now") === "1" ? "" : "1")}
          className={chip(get("available_now") === "1")}
        >
          <span className="h-2 w-2 rounded-full bg-secondary" aria-hidden="true" />
          متاح الآن
        </button>
        {/* Any declared weekly hours — a private session can be booked (review 2026-10-09). */}
        <button
          type="button"
          aria-pressed={get("bookable") === "1"}
          onClick={() => update("bookable", get("bookable") === "1" ? "" : "1")}
          className={chip(get("bookable") === "1")}
        >
          لديه مواعيد للحجز
        </button>

        <span className="mx-1 hidden h-6 w-px bg-line lg:block" aria-hidden="true" />

        <label htmlFor="teacher-rating" className="sr-only">التقييم</label>
        <div className="hidden lg:block">
          <Select id="teacher-rating" value={get("min_rating")} onChange={(event) => update("min_rating", event.target.value)} className={`${pill} py-2`}>
            <option value="">أي تقييم</option>
            {RATINGS.map((rating) => (
              <option key={rating} value={rating}>{ar(rating)} نجوم فأكثر</option>
            ))}
          </Select>
        </div>

        <label htmlFor="teacher-trust" className="sr-only">درجة الثقة</label>
        <div className="hidden lg:block">
          <Select id="teacher-trust" value={get("min_trust_score")} onChange={(event) => update("min_trust_score", event.target.value)} className={`${pill} py-2`}>
            <option value="">أي درجة ثقة</option>
            {TRUST_SCORES.map((score) => (
              <option key={score} value={score}>ثقة {ar(score)} فأكثر</option>
            ))}
          </Select>
        </div>

        <label htmlFor="teacher-language" className="sr-only">لغة التدريس</label>
        <div className="hidden lg:block">
          <Select id="teacher-language" value={get("language")} onChange={(event) => update("language", event.target.value)} className={`${pill} py-2`}>
            <option value="">كل لغات التدريس</option>
            {LANGUAGES.map((lang) => (
              <option key={lang.value} value={lang.value}>{lang.label}</option>
            ))}
          </Select>
        </div>
      </div>

      <FilterSheet id="teacher-sheet" open={sheetOpen} onClose={() => setSheetOpen(false)} onClear={clear}>
        <SheetGroup title="المرحلة">
          <Option pressed={stage === ""} onClick={() => update("grade_level", "")}>كل المراحل</Option>
          {gradeLevels.map((level) => (
            <Option key={level.slug} pressed={stage === level.slug} onClick={() => update("grade_level", level.slug)}>
              {level.name}
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="المادة">
          <Option pressed={subject === ""} onClick={() => update("subject", "")}>{allSubjects}</Option>
          {rankSubjects(subjects).map((item) => (
            <Option key={item.slug} pressed={subject === item.slug} onClick={() => update("subject", item.slug)}>
              {item.name}
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="التقييم">
          <Option pressed={get("min_rating") === ""} onClick={() => update("min_rating", "")}>أي تقييم</Option>
          {RATINGS.map((rating) => (
            <Option key={rating} pressed={get("min_rating") === String(rating)} onClick={() => update("min_rating", String(rating))}>
              <StarIcon className="h-3.5 w-3.5 text-star" aria-hidden="true" />
              {ar(rating)} فأكثر
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="درجة الثقة">
          <Option pressed={get("min_trust_score") === ""} onClick={() => update("min_trust_score", "")}>أي درجة</Option>
          {TRUST_SCORES.map((score) => (
            <Option key={score} pressed={get("min_trust_score") === String(score)} onClick={() => update("min_trust_score", String(score))}>
              {ar(score)} فأكثر
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="لغة التدريس">
          <Option pressed={get("language") === ""} onClick={() => update("language", "")}>كل اللغات</Option>
          {LANGUAGES.map((lang) => (
            <Option key={lang.value} pressed={get("language") === lang.value} onClick={() => update("language", lang.value)}>
              {lang.label}
            </Option>
          ))}
        </SheetGroup>

        <SheetGroup title="الترتيب">
          {SORTS.map((sort) => (
            <Option key={sort.value} pressed={(get("sort") || "rating_desc") === sort.value} onClick={() => update("sort", sort.value)}>
              {sort.label}
            </Option>
          ))}
        </SheetGroup>
      </FilterSheet>
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
    list.find((item) => item.slug === slug)?.name ?? slug;

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
      case "bookable":
        return value === "1" ? "لديه مواعيد للحجز" : null;
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
