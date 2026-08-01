import type { Metadata } from "next";
import Link from "next/link";
import {
  publicApi,
  type Paginated,
  type Taxonomy,
  type TeacherCard as Teacher,
} from "@/lib/public-api";
import { TeacherCard } from "@/components/marketplace/TeacherCard";
import { TeacherFilters } from "@/components/marketplace/TeacherFilters";
import { Pagination } from "@/components/marketplace/Pagination";
import { EmptyState } from "@/components/marketplace/states/EmptyState";
import { ErrorState } from "@/components/marketplace/states/ErrorState";

export const revalidate = 60;

export const metadata: Metadata = {
  title: "المدرسون",
  description:
    "تصفّح المدرّسين المعتمدين حسب المادة والمرحلة الدراسية والسعر والتقييم، واحجز حصة تجريبية.",
};

type SearchParams = Record<string, string | string[] | undefined>;

const FILTER_KEYS = [
  "subject",
  "grade_level",
  "price_min",
  "price_max",
  "min_rating",
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

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <header className="mb-8">
        <h1 className="mb-2 text-3xl font-extrabold text-ink">المدرسون</h1>
        <p className="text-ink-muted">
          {teachers.meta.total.toLocaleString("ar-QA")} مدرّس متاح
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-[280px_1fr]">
        {/* On mobile this becomes a collapsible panel rather than a sidebar; the
            native <details> keeps it keyboard-operable with no extra JS. */}
        <aside>
          <details className="rounded-2xl border border-line bg-white p-5 lg:hidden dark:bg-transparent" open={hasFilters}>
            <summary className="cursor-pointer text-sm font-bold text-ink">
              الفلاتر
            </summary>
            <div className="mt-5">
              <TeacherFilters
                subjects={subjects}
                gradeLevels={gradeLevels}
                idPrefix="m"
              />
            </div>
          </details>

          <div className="hidden rounded-2xl border border-line bg-white p-5 lg:block dark:bg-transparent">
            <h2 className="mb-5 text-sm font-bold text-ink">الفلاتر</h2>
            <TeacherFilters
              subjects={subjects}
              gradeLevels={gradeLevels}
              idPrefix="d"
            />
          </div>
        </aside>

        <section aria-label="نتائج البحث عن المدرّسين">
          {teachers.data.length === 0 ? (
            <EmptyState
              title="لا يوجد مدرّسون مطابقون لبحثك"
              description={
                hasFilters
                  ? "جرّب توسيع نطاق السعر أو إزالة أحد الفلاتر للحصول على نتائج أكثر."
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
              <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
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
    </div>
  );
}
