"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";
import { CloseIcon, SearchIcon } from "@/components/icons";
import { Select } from "@/components/ui/Field";
import { STORE_FILTER_KEYS, type StoreFacets } from "@/lib/public-api";

/**
 * The public store's filter bar — `CourseFilters`' contract: the state lives in
 * the URL, so a filtered page is shareable and the page stays a Server Component.
 *
 * The teacher and subject choices come from `/marketplace/store/facets`, which
 * offers only those that HAVE a listed product — no choice leads to an empty page.
 */
const KINDS = [
  { value: "digital", label: "نسخة رقمية" },
  { value: "physical", label: "نسخة مطبوعة" },
];

const SORTS = [
  { value: "newest", label: "الأحدث" },
  { value: "price_asc", label: "السعر: الأقل أولاً" },
  { value: "price_desc", label: "السعر: الأعلى أولاً" },
];

export function StoreFilters({ facets }: { facets: StoreFacets }) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [pending, startTransition] = useTransition();

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params.toString());

    if (value === "") next.delete(key);
    else next.set(key, value);

    // A narrowed search must not land on page 3 of a 1-page result.
    next.delete("page");

    startTransition(() => router.push(`${pathname}?${next.toString()}`));
  };

  const field =
    "w-full rounded-2xl border border-line bg-surface px-4 py-3 text-sm font-semibold text-ink transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none";

  const hasFilters = STORE_FILTER_KEYS.some((key) => params.get(key));

  const select = (id: string, label: string, key: string, options: Array<{ value: string; label: string }>, all?: string) => (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-xs font-bold text-ink-muted">
        {label}
      </label>
      <Select
        id={id}
        value={params.get(key) ?? (all === undefined ? options[0]?.value ?? "" : "")}
        onChange={(event) => update(key, event.target.value)}
        className={field}
      >
        {all !== undefined && <option value="">{all}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </div>
  );

  return (
    <div
      aria-busy={pending}
      className={`relative mb-8 rounded-3xl border border-line bg-surface-raised p-5 shadow-sm transition-opacity motion-reduce:transition-none sm:p-6 ${
        pending ? "opacity-70" : ""
      }`}
    >
      <div className="flex flex-col gap-5 lg:flex-row lg:items-end">
        <span
          aria-hidden="true"
          className="hidden h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/20 lg:grid"
        >
          <SearchIcon className="h-6 w-6" />
        </span>

        <div className="grid flex-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {select(
            "f-teacher",
            "المدرّس",
            "teacher",
            facets.teachers.map((teacher) => ({ value: teacher.uuid, label: teacher.name ?? "مدرّس" })),
            "كل المدرّسين",
          )}
          {select(
            "f-subject",
            "المادة",
            "subject",
            facets.subjects.map((subject) => ({ value: subject.slug, label: subject.name })),
            "كل المواد",
          )}
          {select("f-kind", "نوع المنتج", "kind", KINDS, "الكل")}
          {select("f-sort", "ترتيب حسب", "sort", SORTS)}
        </div>
      </div>

      {hasFilters && (
        <button
          type="button"
          onClick={() => startTransition(() => router.push(pathname))}
          className="mt-5 inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-4 py-2 text-sm font-bold text-primary-ink transition duration-200 ease-out hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
        >
          <CloseIcon className="h-4 w-4" aria-hidden="true" />
          إزالة كل الفلاتر
        </button>
      )}
    </div>
  );
}
