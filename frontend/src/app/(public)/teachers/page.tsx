import type { Metadata } from "next";
import Link from "next/link";
import {
  publicApi,
  type Paginated,
  type Taxonomy,
  type TeacherCard as Teacher,
} from "@/lib/public-api";
import { TeacherCard } from "@/components/marketplace/TeacherCard";
import {
  ActiveFilters,
  TeacherFilters,
} from "@/components/marketplace/TeacherFilters";
import { SubjectPills } from "@/components/marketplace/SubjectPills";
import { Pagination } from "@/components/ui/Pagination";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

export const revalidate = 60;

export const metadata: Metadata = {
  title: "المدرسون",
  description:
    "تصفّح المدرّسين المعتمدين حسب المادة والمرحلة الدراسية والتقييم ودرجة الثقة، واحجز حصة تجريبية.",
};

type SearchParams = Record<string, string | string[] | undefined>;

const FILTER_KEYS = [
  "subject",
  "grade_level",
  /*
   * ⚠️ No `price_min`/`price_max` (spec 006, FR-021و). The API answers 422 for
   * either, so forwarding a stale bookmark's query string would turn the whole
   * listing into an error page — this list is the filter that stops that.
   */
  "min_rating",
  "min_trust_score",
  "language",
  "available_now",
  "q",
  "sort",
  "page",
] as const;

function toQuery(params: SearchParams): Record<string, string | undefined> {
  return Object.fromEntries(
    FILTER_KEYS.map((key) => [
      key,
      typeof params[key] === "string" ? (params[key] as string) : undefined,
    ]),
  );
}

export default async function TeachersPage({
  searchParams,
}: {
  searchParams: Promise<SearchParams>;
}) {
  const params = await searchParams;

  let teachers: Paginated<Teacher>;
  let subjects: Taxonomy[] = [];
  let gradeLevels: Taxonomy[] = [];

  try {
    [teachers, subjects, gradeLevels] = await Promise.all([
      publicApi.teachers(toQuery(params)),
      publicApi.subjects(),
      publicApi.gradeLevels(),
    ]);
  } catch {
    return (
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

  const hasFilters = FILTER_KEYS.slice(0, -2).some(
    (key) => typeof params[key] === "string" && params[key] !== "",
  );

  const query = toQuery(params);

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      {/*
        | The sidebar is gone, and it was the page's central mistake.
        |
        | A 280px column of six identical dropdowns ran taller than the results
        | it filtered — with five teachers seeded, two thirds of the page was
        | empty space beside a form. Worse, it made SUBJECT (how a parent starts:
        | "I need someone for physics") the second row of a control stack, no
        | more prominent than "minimum trust score".
        |
        | So the axis comes first as pills, refinement sits under it in one bar,
        | and the results get the whole width.
      */}
      <header className="mb-6">
        <h1 className="mb-2 text-3xl font-extrabold text-ink sm:text-4xl">
          المدرسون
        </h1>
        <p className="text-ink-muted">
          {teachers.meta.total.toLocaleString("ar-QA")} مدرّس متاح
        </p>
      </header>

      <div className="mb-6">
        <h2 className="sr-only">تصفية حسب المادة</h2>
        <SubjectPills subjects={subjects} params={query} />
      </div>

      {/* `open` on a filtered load, so arriving from a shared link shows what is
          narrowing the list rather than hiding it behind a summary. */}
      <details
        open={hasFilters}
        className="group mb-4 rounded-3xl border border-line bg-surface-raised px-5 py-4 lg:hidden"
      >
        <summary className="flex cursor-pointer items-center justify-between text-sm font-bold text-ink">
          خيارات أدق
          <span
            className="text-ink-muted transition duration-200 group-open:rotate-180"
            aria-hidden="true"
          >
            ▾
          </span>
        </summary>
        <div className="mt-5">
          <TeacherFilters gradeLevels={gradeLevels} idPrefix="m" />
        </div>
      </details>

      <div className="mb-4 hidden rounded-3xl border border-line bg-surface-raised px-5 py-4 lg:block">
        <h2 className="sr-only">خيارات تصفية أدق</h2>
        <TeacherFilters gradeLevels={gradeLevels} idPrefix="d" />
      </div>

      <div className="mb-8">
        <ActiveFilters subjects={subjects} gradeLevels={gradeLevels} />
      </div>

      <section aria-label="نتائج البحث عن المدرّسين">
          {teachers.data.length === 0 ? (
            <EmptyState
              title="لا يوجد مدرّسون مطابقون لبحثك"
              description={
                hasFilters
                  ? "جرّب اختيار مادة أخرى أو إزالة أحد الفلاتر للحصول على نتائج أكثر."
                  : "لم ينضم مدرّسون إلى المنصة بعد. إن كنت مدرّساً، يمكنك التقديم الآن."
              }
              action={
                hasFilters ? (
                  <Link
                    href="/teachers"
                    className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
                  >
                    إزالة كل الفلاتر
                  </Link>
                ) : (
                  <Link
                    href="/signup/teacher"
                    className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
                  >
                    سجّل كمدرّس
                  </Link>
                )
              }
            />
          ) : (
            <>
              <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {teachers.data.map((teacher) => (
                  <TeacherCard key={teacher.uuid} teacher={teacher} />
                ))}
              </div>

              <Pagination
                currentPage={teachers.meta.current_page}
                lastPage={teachers.meta.last_page}
                searchParams={params}
              />
            </>
          )}
      </section>
    </div>
  );
}
