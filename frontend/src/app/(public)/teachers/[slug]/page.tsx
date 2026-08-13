import { CheckIcon } from "@/components/icons";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { publicApi, NotFoundError, type TeacherDetail } from "@/lib/public-api";
import { StarRating } from "@/components/marketplace/StarRating";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { TrustScoreBreakdown } from "@/components/marketplace/TrustScoreBreakdown";
import { AvailabilityCalendar } from "@/components/marketplace/AvailabilityCalendar";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { ReviewsTab } from "@/components/marketplace/ReviewsTab";
import { EmptyState } from "@/components/ui/states/EmptyState";
import {
  ProfileTabs,
  isProfileTab,
  type ProfileTabId,
} from "@/components/marketplace/ProfileTabs";

type Params = { slug: string };
type Search = { tab?: string };

// `key` because the API resolves a slug OR a uuid: every profile link shared
// before the slug existed is a uuid, and refusing those would 404 pages that
// still exist.
async function loadTeacher(key: string): Promise<TeacherDetail> {
  try {
    const { data } = await publicApi.teacher(key);

    return data;
  } catch (error) {
    // The API answers 404 identically for "no such teacher", "not approved",
    // "unpublished" and "workspace withdrew". Preserve that here: a distinct page
    // for any of them would confirm the profile exists.
    if (error instanceof NotFoundError) notFound();
    throw error;
  }
}

export async function generateMetadata({
  params,
}: {
  params: Promise<Params>;
}): Promise<Metadata> {
  const { slug } = await params;

  try {
    const teacher = await loadTeacher(slug);

    return {
      title: `${teacher.name} — ${teacher.headline ?? "مدرّس"}`,
      description:
        teacher.bio?.slice(0, 155) ??
        `احجز حصة مع ${teacher.name}، ${teacher.years_experience} سنوات خبرة.`,
    };
  } catch {
    return { title: "غير متاح" };
  }
}

function QuickStats({ stats }: { stats: TeacherDetail["stats"] }) {
  const items = [
    { label: "طالب درّسهم", value: stats.students_taught },
    { label: "حصة مكتملة", value: stats.completed_sessions },
    { label: "معدّل الاستجابة", value: stats.response_rate, suffix: "٪" },
    { label: "نسبة الحضور", value: stats.attendance_rate, suffix: "٪" },
  ];

  // Four bordered boxes of big-number-small-label is the hero-metric template,
  // and it was standing inside the «نبذة» tab as if these facts were part of the
  // biography. They are facts about the teacher whichever tab is open, so they
  // sit under the masthead — as a rule of figures separated by dividers, which
  // is what a row of related numbers looks like when it is not four cards.
  return (
    <dl className="grid grid-cols-2 gap-x-8 gap-y-5 sm:gap-x-12">
      {items.map((item) => (
        <div key={item.label} className="flex flex-col">
          <dd className="text-2xl font-extrabold text-primary-ink">
            {item.value === null
              ? "—"
              : `${item.value.toLocaleString("ar-QA")}${item.suffix ?? ""}`}
          </dd>
          <dt className="text-xs text-ink-muted">{item.label}</dt>
        </div>
      ))}
    </dl>
  );
}

export default async function TeacherProfilePage({
  params,
  searchParams,
}: {
  params: Promise<Params>;
  searchParams: Promise<Search>;
}) {
  const { slug } = await params;
  const { tab } = await searchParams;

  const teacher = await loadTeacher(slug);
  const active: ProfileTabId = isProfileTab(tab) ? tab : "about";

  // 308 to the canonical slug when the visitor arrived on the old uuid URL.
  // Serving the same profile at two addresses splits its ranking between them
  // and is the reason a redirect is permanent rather than a rewrite: the search
  // engine has to be told which of the two to keep.
  if (teacher.slug && decodeURIComponent(slug) !== teacher.slug) {
    permanentRedirect(
      `/teachers/${teacher.slug}${active === "about" ? "" : `?tab=${active}`}`,
    );
  }

  return (
    // pb-28 on mobile keeps the fixed booking bar from covering the last section.
    <div className="mx-auto max-w-7xl px-4 py-10 pb-28 sm:px-6 lg:pb-10">
      {/*
        | The masthead spans the page; the two-column grid starts BELOW it.
        |
        | It used to be the first cell of a `[1fr_320px]` grid, which capped the
        | teacher's name, headline, rating and subjects at two thirds of the
        | width while a booking box with two buttons held the other third at the
        | top of the page. The one thing every visitor is here to read was the
        | narrower of the two.
      */}
      <header className="mb-8 flex flex-col gap-8 rounded-3xl border border-line bg-surface-raised p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-col gap-6 sm:flex-row">
          {teacher.photo_url ? (
            <img
              src={teacher.photo_url}
              alt=""
              className="h-32 w-32 shrink-0 rounded-2xl object-cover"
            />
          ) : (
            <span
              className="flex h-32 w-32 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-4xl font-bold text-primary-ink"
              aria-hidden="true"
            >
              {teacher.name.charAt(0)}
            </span>
          )}

          <div>
            <h1 className="mb-2 flex flex-wrap items-center gap-2 text-2xl font-extrabold text-ink sm:text-3xl">
              {teacher.name}
              {teacher.is_verified && (
                <span className="inline-flex items-center gap-1 rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary-ink">
                  <CheckIcon className="h-3.5 w-3.5" />
                  موثّق
                </span>
              )}
            </h1>

            <p className="mb-3 text-lg text-ink-muted">{teacher.headline}</p>

            <div className="mb-4 flex flex-wrap items-center gap-3">
              <StarRating
                value={teacher.average_rating}
                count={teacher.reviews_count}
                size="lg"
              />
              <TrustScoreBadge
                score={teacher.trust_score}
                band={teacher.trust_score_band}
              />
            </div>

            {teacher.subjects.length > 0 && (
              <ul className="mb-4 flex flex-wrap gap-2">
                {teacher.subjects.map((subject) => (
                  <li key={subject.slug}>
                    <Link
                      href={`/teachers?subject=${subject.slug}`}
                      className="inline-block rounded-full bg-primary-soft px-3 py-1 text-sm font-medium text-primary-ink transition duration-200 ease-out hover:brightness-95 active:scale-[0.97] active:duration-100"
                    >
                      {subject.name_ar}
                    </Link>
                  </li>
                ))}
              </ul>
            )}

            {/* The stage was published by the API and shown nowhere. It is the
                second thing a parent checks after the subject — «ثانوي» decides
                whether this teacher is relevant at all. */}
            {teacher.grade_levels.length > 0 && (
              <p className="text-sm text-ink-muted">
                يدرّس{" "}
                {teacher.grade_levels.map((level) => level.name_ar).join(" · ")}
              </p>
            )}
          </div>
        </div>

        {/* The facts, INSIDE the masthead rather than in a strip beneath it.
            They used to sit in the «نبذة» tab, which made "how many students has
            he taught" a property of his biography rather than of him — and
            moving them to their own full-width row left the masthead with an
            empty half and the numbers floating under it. One block: who he is,
            and what he has actually done. */}
        <div className="shrink-0 border-t border-line pt-6 lg:border-s lg:border-t-0 lg:ps-10 lg:pt-0">
          <h2 className="sr-only">إحصائيات المدرّس</h2>
          <QuickStats stats={teacher.stats} />
        </div>
      </header>

      <div className="grid gap-8 lg:grid-cols-[1fr_320px] lg:items-start">
        {/* Sticky booking panel (FR-054): spans both content rows so it stays put
            while the tabs scroll. On mobile it sits between the identity block and
            the tabs, which is where a price belongs on a phone. */}
        <aside className="lg:col-start-2 lg:row-start-1">
          {/* ⚠️ THE STICKY IS ON THE PANEL, NOT ON THE `aside`. A grid item's
              containing block is its grid area, and this one is a single-row
              grid — so the aside was already as tall as the column beside it and
              a sticky box with no room to travel never moves at all. It read as
              sticky and scrolled away with the page, taking the only desktop
              booking CTA off screen at the bottom of the profile (FR-054), where
              below `lg` a fixed bar exists precisely to prevent that. Playwright
              caught it; no unit test can see a computed layout. */}
          <div className="rounded-3xl border border-line bg-surface-raised p-6 lg:sticky lg:top-24">
            {/* ⚠️ The price is gone from this panel (spec 006, FR-021و · FR-021هـ).
                It is not hidden pending a redesign: the platform is the seller
                now, the student's total is computed per package on the purchase
                screen, and the teacher's own rate is what they are PAID — a
                number FR-021ب keeps off every student-facing surface.

                The panel keeps its job. What sold the booking was never the
                number; it was knowing who this teacher is, which is what stands
                here instead. */}
            <p className="mb-1 text-sm text-ink-muted">الحجز مع</p>
            <p className="mb-5 text-2xl font-extrabold text-ink">{teacher.name}</p>

            <Link
              href={`/signup/student?teacher=${teacher.uuid}`}
              className="mb-3 block rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              احجز الآن
            </Link>
            <Link
              href={`/signup/student?teacher=${teacher.uuid}&trial=1`}
              className="block rounded-full border border-primary px-5 py-3 text-center text-base font-semibold text-primary-ink transition duration-200 ease-out hover:bg-primary-soft active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              حجز حصة تجريبية
            </Link>

            {teacher.available_now && (
              <p className="mt-4 flex items-center justify-center gap-2 text-sm font-medium text-secondary-ink">
                <span
                  className="h-2 w-2 rounded-full bg-secondary"
                  aria-hidden="true"
                />
                متاح الآن
              </p>
            )}
          </div>

          {/* Under the booking panel, not beside the biography.
              FR-024 and the product's third differentiator make the visible
              factor breakdown load-bearing, so it stays on the first screen of
              desktop — but as a 380px column nested inside an already-narrowed
              content column it squeezed the bio to about 440px and read as the
              page's subject. It belongs where the decision is made. */}
          <div className="mt-6">
            <TrustScoreBreakdown
              score={teacher.trust_score}
              band={teacher.trust_score_band}
              factors={teacher.trust_score_factors}
            />
          </div>
        </aside>

        <div className="lg:col-start-1 lg:row-start-1">
          <ProfileTabs slug={teacher.slug ?? teacher.uuid} active={active}>
            {active === "about" && (
              <div>
                <div className="space-y-8">
                  <section aria-labelledby="bio-heading">
                    <h2
                      id="bio-heading"
                      className="mb-3 text-lg font-bold text-ink"
                    >
                      نبذة عن المدرّس
                    </h2>
                    <p className="whitespace-pre-line leading-relaxed text-ink-muted">
                      {teacher.bio ?? "لم يضف هذا المدرّس نبذة بعد."}
                    </p>
                  </section>

                  {teacher.qualifications.length > 0 && (
                    <section aria-labelledby="quals-heading">
                      <h2
                        id="quals-heading"
                        className="mb-3 text-lg font-bold text-ink"
                      >
                        المؤهلات والشهادات
                      </h2>
                      <ul className="space-y-2">
                        {teacher.qualifications.map((qualification) => (
                          <li
                            key={qualification}
                            className="flex items-start gap-2 text-ink-muted"
                          >
                            <CheckIcon className="mt-1 h-4 w-4 shrink-0 text-secondary-ink" />
                            {qualification}
                          </li>
                        ))}
                      </ul>
                    </section>
                  )}

                </div>
              </div>
            )}

            {active === "courses" &&
              (teacher.courses.length === 0 ? (
                <EmptyState
                  title="لا توجد كورسات منشورة لهذا المدرّس"
                  description="يمكنك حجز حصة فردية معه مباشرة عبر زر الحجز."
                />
              ) : (
                <div className="grid gap-6 sm:grid-cols-2">
                  {teacher.courses.map((course) => (
                    <CourseCard key={course.uuid} course={course} />
                  ))}
                </div>
              ))}

            {active === "reviews" && (
              <ReviewsTab teacherUuid={teacher.uuid} reviews={teacher.reviews} />
            )}

            {active === "schedule" && (
              <AvailabilityCalendar slots={teacher.availability} />
            )}
          </ProfileTabs>

          {teacher.faqs.length > 0 && (
            <section className="mt-12">
              <FaqAccordion
                items={teacher.faqs}
                heading="أسئلة شائعة عن هذا المدرّس"
              />
            </section>
          )}
        </div>
      </div>

      {/* Below lg the side panel is stacked at the top and scrolls away, so the
          booking CTA gets a fixed bar of its own. FR-054 does not exempt mobile,
          and mobile is where a lost CTA costs the most. */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:hidden">
        <div className="flex items-center gap-3">
          <p className="shrink-0 text-sm text-ink-muted">
            <span className="block text-lg font-bold text-ink">
              {teacher.name}
            </span>
            {teacher.subjects[0]?.name_ar ?? "حصص خاصة"}
          </p>
          <Link
            href={`/signup/student?teacher=${teacher.uuid}`}
            className="flex-1 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            احجز الآن
          </Link>
        </div>
      </div>
    </div>
  );
}
