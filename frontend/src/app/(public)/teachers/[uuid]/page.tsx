import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { publicApi, NotFoundError, type TeacherDetail } from "@/lib/public-api";
import { StarRating } from "@/components/marketplace/StarRating";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { TrustScoreBreakdown } from "@/components/marketplace/TrustScoreBreakdown";
import { AvailabilityCalendar } from "@/components/marketplace/AvailabilityCalendar";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { EmptyState } from "@/components/marketplace/states/EmptyState";
import {
  ProfileTabs,
  isProfileTab,
  type ProfileTabId,
} from "@/components/marketplace/ProfileTabs";
import { CURRENCY_LABEL } from "@/lib/platform";

type Params = { uuid: string };
type Search = { tab?: string };

async function loadTeacher(uuid: string): Promise<TeacherDetail> {
  try {
    const { data } = await publicApi.teacher(uuid);

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
  const { uuid } = await params;

  try {
    const teacher = await loadTeacher(uuid);

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

  return (
    <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      {items.map((item) => (
        <div
          key={item.label}
          className="rounded-xl border border-line p-4 text-center"
        >
          <dt className="order-2 text-xs text-ink-muted">{item.label}</dt>
          <dd className="order-1 text-2xl font-extrabold text-primary">
            {item.value === null
              ? "—"
              : `${item.value.toLocaleString("ar-QA")}${item.suffix ?? ""}`}
          </dd>
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
  const { uuid } = await params;
  const { tab } = await searchParams;

  const teacher = await loadTeacher(uuid);
  const active: ProfileTabId = isProfileTab(tab) ? tab : "about";

  return (
    // pb-28 on mobile keeps the fixed booking bar from covering the last section.
    <div className="mx-auto max-w-7xl px-4 py-10 pb-28 sm:px-6 lg:pb-10">
      {/* One page-level grid, not a header-scoped one: `sticky` only sticks inside
          its containing block, so an aside nested in <header> scrolls away the
          moment the header ends — which is exactly what FR-054 forbids. */}
      <div className="grid gap-8 lg:grid-cols-[1fr_320px] lg:items-start">
        <header className="flex flex-col gap-6 sm:flex-row lg:col-start-1 lg:row-start-1">
          {teacher.photo_url ? (
            <img
              src={teacher.photo_url}
              alt=""
              className="h-32 w-32 shrink-0 rounded-2xl object-cover"
            />
          ) : (
            <span
              className="flex h-32 w-32 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-4xl font-bold text-primary"
              aria-hidden="true"
            >
              {teacher.name.charAt(0)}
            </span>
          )}

          <div>
            <h1 className="mb-2 flex flex-wrap items-center gap-2 text-2xl font-extrabold text-ink sm:text-3xl">
              {teacher.name}
              {teacher.is_verified && (
                <span className="inline-flex items-center gap-1 rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary">
                  <svg
                    className="h-3.5 w-3.5"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                  >
                    <path
                      fillRule="evenodd"
                      d="M16.4 6.4a1 1 0 010 1.4l-6.6 6.6a1 1 0 01-1.4 0L5.1 11.1a1 1 0 111.4-1.4l2.6 2.6 5.9-5.9a1 1 0 011.4 0z"
                      clipRule="evenodd"
                    />
                  </svg>
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
              <ul className="flex flex-wrap gap-2">
                {teacher.subjects.map((subject) => (
                  <li key={subject.slug}>
                    <Link
                      href={`/teachers?subject=${subject.slug}`}
                      className="rounded-lg bg-primary-soft px-2.5 py-1 text-sm text-primary hover:underline"
                    >
                      {subject.name_ar}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </header>

        {/* Sticky booking panel (FR-054): spans both content rows so it stays put
            while the tabs scroll. On mobile it sits between the identity block and
            the tabs, which is where a price belongs on a phone. */}
        <aside className="lg:col-start-2 lg:row-start-1 lg:row-span-2 lg:sticky lg:top-24">
          <div className="rounded-2xl border border-line bg-white p-6 dark:bg-transparent">
            <p className="mb-1 text-sm text-ink-muted">السعر لكل حصة</p>
            <p className="mb-5 text-3xl font-extrabold text-ink">
              {teacher.hourly_rate}{" "}
              <span className="text-base font-medium text-ink-muted">
                {CURRENCY_LABEL}
              </span>
            </p>

            <Link
              href={`/signup/student?teacher=${teacher.uuid}`}
              className="mb-3 block rounded-xl bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              احجز الآن
            </Link>
            <Link
              href={`/signup/student?teacher=${teacher.uuid}&trial=1`}
              className="block rounded-xl border border-primary px-5 py-3 text-center text-base font-semibold text-primary transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              حجز حصة تجريبية
            </Link>

            {teacher.available_now && (
              <p className="mt-4 flex items-center justify-center gap-2 text-sm font-medium text-secondary">
                <span
                  className="h-2 w-2 rounded-full bg-secondary"
                  aria-hidden="true"
                />
                متاح الآن
              </p>
            )}
          </div>
        </aside>

        <div className="lg:col-start-1 lg:row-start-2">
          <ProfileTabs uuid={teacher.uuid} active={active}>
            {active === "about" && (
              <div className="grid gap-8 lg:grid-cols-[1fr_380px]">
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
                            <svg
                              className="mt-1 h-4 w-4 shrink-0 text-secondary"
                              viewBox="0 0 20 20"
                              fill="currentColor"
                              aria-hidden="true"
                            >
                              <path
                                fillRule="evenodd"
                                d="M16.4 6.4a1 1 0 010 1.4l-6.6 6.6a1 1 0 01-1.4 0L5.1 11.1a1 1 0 111.4-1.4l2.6 2.6 5.9-5.9a1 1 0 011.4 0z"
                                clipRule="evenodd"
                              />
                            </svg>
                            {qualification}
                          </li>
                        ))}
                      </ul>
                    </section>
                  )}

                  <section aria-labelledby="stats-heading">
                    <h2
                      id="stats-heading"
                      className="mb-3 text-lg font-bold text-ink"
                    >
                      إحصائيات سريعة
                    </h2>
                    <QuickStats stats={teacher.stats} />
                  </section>
                </div>

                <TrustScoreBreakdown
                  score={teacher.trust_score}
                  band={teacher.trust_score_band}
                  factors={teacher.trust_score_factors}
                />
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
              <EmptyState
                title="لا توجد تقييمات بعد"
                description="لم يقيّم أي طالب هذا المدرّس حتى الآن. التقييمات تظهر بعد إتمام الحصص."
              />
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
              {teacher.hourly_rate}
            </span>
            {CURRENCY_LABEL} / الحصة
          </p>
          <Link
            href={`/signup/student?teacher=${teacher.uuid}`}
            className="flex-1 rounded-xl bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            احجز الآن
          </Link>
        </div>
      </div>
    </div>
  );
}
