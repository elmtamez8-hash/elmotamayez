import type { Metadata } from "next";
import Link from "next/link";
import {
  publicApi,
  type CourseCard as Course,
  type Paginated,
  type Taxonomy,
} from "@/lib/public-api";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { CourseFilters } from "@/components/marketplace/CourseFilters";
import { Pagination } from "@/components/ui/Pagination";
import { PageBanner } from "@/components/ui/PageBanner";
import { BookIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { counted } from "@/lib/labels";

export const revalidate = 60;

export const metadata: Metadata = {
  title: "الكورسات",
  description:
    "تصفّح الكورسات المباشرة والمسجّلة حسب المادة والمرحلة الدراسية ونوع الكورس والسعر.",
};

type SearchParams = Record<string, string | string[] | undefined>;

const FILTER_KEYS = [
  "subject",
  "grade_level",
  "type",
  /*
   * ⚠️ No `price_min`/`price_max` (spec 006, FR-021و). The API answers 422 for
   * either, so forwarding a stale bookmark's query string would turn the whole
   * listing into an error page — this list is the filter that stops that.
   */
  "teacher",
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

export default async function CoursesPage({
  searchParams,
}: {
  searchParams: Promise<SearchParams>;
}) {
  const params = await searchParams;

  let courses: Paginated<Course>;
  let subjects: Taxonomy[] = [];
  let gradeLevels: Taxonomy[] = [];

  try {
    [courses, subjects, gradeLevels] = await Promise.all([
      publicApi.courses(toQuery(params)),
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
      <PageBanner
        icon={BookIcon}
        image="/marketplace/banner-courses.webp"
        title="الكورسات"
        description="مسارات كاملة — مباشرة ومسجّلة — يبنيها المدرّس ويتابع فيها تقدّمك درساً بدرس، لا حصصاً متفرّقة."
      >
        <p className="mt-5 inline-flex items-center rounded-full bg-white/15 px-4 py-1.5 text-sm font-semibold text-white backdrop-blur-sm">
          {counted(courses.meta.total, {
            one: "كورس متاح",
            two: "كورسان متاحان",
            few: "كورسات متاحة",
            many: "كورساً متاحاً",
          })}
        </p>
      </PageBanner>

      <CourseFilters subjects={subjects} gradeLevels={gradeLevels} />

      <section aria-label="نتائج البحث عن الكورسات">
        {courses.data.length === 0 ? (
          <EmptyState
            title="لا توجد كورسات مطابقة لبحثك"
            description={
              hasFilters
                ? "جرّب تغيير نوع الكورس أو توسيع نطاق السعر."
                : "لم تُنشر كورسات على المنصة بعد. يمكنك حجز حصة فردية مع أحد المدرّسين."
            }
            action={
              <Link
                href={hasFilters ? "/courses" : "/teachers"}
                className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
              >
                {hasFilters ? "إزالة كل الفلاتر" : "تصفّح المدرّسين"}
              </Link>
            }
          />
        ) : (
          <>
            <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
              {courses.data.map((course) => (
                <CourseCard key={course.uuid} course={course} />
              ))}
            </div>

            <Pagination
              currentPage={courses.meta.current_page}
              lastPage={courses.meta.last_page}
              searchParams={params}
            />
          </>
        )}
      </section>
    </div>
  );
}
