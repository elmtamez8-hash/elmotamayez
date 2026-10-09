import type { Metadata } from "next";
import { publicPageMetadata } from "@/lib/seo";
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
import { Pagination } from "@/components/ui/Pagination";
import { PageBanner } from "@/components/ui/PageBanner";
import { SearchIcon, TeacherIcon, UsersIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { counted } from "@/lib/labels";

import { arabicNumber } from "@/lib/numerals";
export const revalidate = 60;

// Canonical and `og:url` on the bare path — see `publicPageMetadata()`.
export function generateMetadata(): Promise<Metadata> {
  return publicPageMetadata({
    path: "/teachers",
    title: "المدرسون",
    description:
      "تصفّح المدرّسين المعتمدين حسب المادة والمرحلة الدراسية والتقييم ودرجة الثقة، واحجز حصة تجريبية.",
    image: "/marketplace/banner-teachers.webp",
  });
}

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
      // Scoped to the chosen stage, so the subject control opens with what is
      // taught there rather than with every subject on the platform.
      publicApi.subjects(
        typeof params.grade_level === "string" ? params.grade_level : undefined,
      ),
      publicApi.gradeLevels(),
    ]);
  } catch {
    return (
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

  // slice(0, -2) drops `sort` and `page`, which narrow nothing.
  const appliedCount = FILTER_KEYS.slice(0, -2).filter(
    (key) => typeof params[key] === "string" && params[key] !== "",
  ).length;

  const hasFilters = appliedCount > 0;

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
        | So every control sits in one bar and the results get the whole width —
        | and SUBJECT leads that bar, narrowed by the stage above it.
      */}
      <PageBanner
        icon={UsersIcon}
        image="/marketplace/banner-teachers.webp"
        title="المدرسون"
        description="كل مدرّس هنا مرّ بمراجعة أكاديمية قبل أن يظهر، ودرجة ثقته محسوبة من أدائه الفعلي لا من وصفه لنفسه."
      >
        <p className="mt-5 inline-flex items-center rounded-full bg-white/15 px-4 py-1.5 text-sm font-semibold text-white backdrop-blur-sm">
          {counted(teachers.meta.total, {
            one: "مدرّس متاح",
            two: "مدرّسان متاحان",
            few: "مدرّسين متاحين",
            many: "مدرّساً متاحاً",
            other: "مدرّس متاح",
          })}
        </p>
      </PageBanner>

      {/* ⚠️ CLOSED on a filtered load, and open only when nothing matched.
          It used to open whenever any filter was set, which on a phone meant six
          stacked controls between the heading and the first teacher — the
          visitor pays a screenful to be told what they already chose. The chips
          below say what is applied in one line and remove it in one tap; the
          panel is only worth the space when the answer is "nothing matched" and
          widening the search is the next move. */}
      <details
        open={teachers.data.length === 0 && hasFilters}
        className="group mb-4 rounded-3xl border border-line bg-surface-raised px-5 py-4 shadow-sm lg:hidden"
      >
        <summary className="flex cursor-pointer items-center justify-between text-sm font-bold text-ink">
          <span className="flex items-center gap-3">
            <span className="grid h-9 w-9 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-200 group-open:bg-primary group-open:text-white">
              <SearchIcon className="h-4 w-4" />
            </span>
            خيارات أدق
            {appliedCount > 0 && (
              <span className="ms-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-bold text-white">
                {arabicNumber(appliedCount)}
              </span>
            )}
          </span>
          <span
            className="text-ink-muted transition duration-200 group-open:rotate-180"
            aria-hidden="true"
          >
            ▾
          </span>
        </summary>
        <div className="mt-5">
          <TeacherFilters subjects={subjects} gradeLevels={gradeLevels} idPrefix="m" />
        </div>
      </details>

      {/* The desktop console: raised and tinted with the brand's shadow so it
          reads as the page's one control surface, not a form left on the page. */}
      <div className="mb-4 hidden rounded-3xl border border-line bg-surface-raised px-6 py-5 shadow-lg shadow-primary/10 lg:block">
        <h2 className="mb-4 flex items-center gap-3 text-base font-extrabold text-ink">
          <span className="grid h-10 w-10 place-items-center rounded-xl bg-primary text-white">
            <SearchIcon className="h-5 w-5" />
          </span>
          خيارات تصفية أدق
        </h2>
        <TeacherFilters subjects={subjects} gradeLevels={gradeLevels} idPrefix="d" />
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
                  // `reveal` on the wrapper, not inside the card: the card is
                  // shared with the home page and carries no motion of its own.
                  <div key={teacher.uuid} className="reveal grid">
                    <TeacherCard teacher={teacher} />
                  </div>
                ))}
              </div>

              <Pagination basePath="/teachers"
                currentPage={teachers.meta.current_page}
                lastPage={teachers.meta.last_page}
                searchParams={params}
              />
            </>
          )}
      </section>

      {/* The other side of the marketplace. Only under a list that HAS teachers:
          the empty state already carries this same door, and two of them on one
          screen ask the reader to compare identical buttons. */}
      {teachers.data.length > 0 && (
        <section
          aria-labelledby="teach-with-us"
          className="reveal bg-squares relative isolate mt-16 overflow-hidden rounded-3xl bg-primary px-6 py-10 text-white shadow-xl shadow-primary/20 sm:px-10 lg:flex lg:items-center lg:justify-between lg:gap-10 lg:px-14"
        >
          <TeacherIcon
            className="absolute -bottom-10 end-6 -z-10 h-56 w-56 text-white/10"
          />
          <div className="max-w-xl">
            <h2
              id="teach-with-us"
              className="mb-3 text-3xl font-extrabold text-balance sm:text-4xl"
            >
              تدرّس مادة يحتاجها الطلاب؟
            </h2>
            <p className="leading-relaxed text-white/80">
              قدّم طلبك، ونراجع مؤهلاتك قبل أن يظهر ملفّك هنا بجانب هؤلاء المدرّسين.
            </p>
          </div>
          <Link
            href="/signup/teacher"
            className="mt-6 inline-flex shrink-0 rounded-full bg-accent px-7 py-3.5 text-base font-extrabold text-accent-foreground shadow-lg shadow-primary-ink/30 transition duration-200 ease-out hover:-translate-y-0.5 hover:brightness-105 active:translate-y-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0 lg:mt-0"
          >
            سجّل كمدرّس
          </Link>
        </section>
      )}
    </div>
  );
}
