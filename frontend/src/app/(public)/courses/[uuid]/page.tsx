import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CohortList } from "@/components/marketplace/CohortList";
import { CourseCurriculum } from "@/components/marketplace/CourseCurriculum";
import { PrivateSessionRequestForm } from "@/components/courses/PrivateSessionRequestForm";
import { PromoVideoButton } from "@/components/courses/PromoVideoButton";
import { StarRating } from "@/components/marketplace/StarRating";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { formatMinorMoney } from "@/lib/labels";
import {
  NotFoundError,
  publicApi,
  type AvailabilityItem,
  type CourseDetail,
} from "@/lib/public-api";
import { siteUrl } from "@/lib/site";

type Params = { uuid: string };

const TYPE_LABELS: Record<CourseDetail["type"], string> = {
  individual: "فردي",
  group: "جماعي",
  recorded: "مسجّل",
};

/*
 * ⚠️ THE ADDRESS IS A uuid, NOT A slug. `courses.slug` is unique per
 * (workspace_id, slug) — inside one workspace only — so two teachers naming a
 * course «الرياضيات ٣» produce the same slug and a public route with no
 * workspace to read cannot tell them apart. The slug is in the payload for
 * display and is deliberately not the route segment.
 */
async function loadCourse(uuid: string): Promise<CourseDetail> {
  try {
    const { data } = await publicApi.course(uuid);

    return data;
  } catch (error) {
    /*
     * The API answers 404 identically for «no such course», «draft», «teacher
     * not approved» and «workspace withdrew from the marketplace». Preserve that
     * here: a distinct page for any of them confirms the course exists, which is
     * the one thing the identical refusal exists to withhold.
     */
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
    const course = await loadCourse(uuid);

    return {
      title: course.teacher
        ? `${course.title} — ${course.teacher.name}`
        : course.title,
      description:
        course.description?.slice(0, 155) ??
        `${course.title}: ${course.lessons_count.toLocaleString("ar-QA")} درساً على منصّتنا.`,
      // Absolute, for the reason the home page's is: a relative canonical
      // resolves against whichever host the crawler arrived on.
      alternates: { canonical: siteUrl(`/courses/${course.uuid}`) },
    };
  } catch {
    return { title: "غير متاح" };
  }
}

async function loadAvailability(course: CourseDetail): Promise<AvailabilityItem[]> {
  const key = course.teacher?.slug ?? course.teacher?.uuid;

  if (key === undefined) return [];

  try {
    const { data } = await publicApi.teacher(key);

    return data.availability;
  } catch {
    return [];
  }
}

function hours(seconds: number): string | null {
  const value = Math.round(seconds / 3600);

  if (value <= 0) return null;
  if (value === 1) return "ساعة";
  if (value === 2) return "ساعتان";
  if (value <= 10) return `${value.toLocaleString("ar-QA")} ساعات`;

  return `${value.toLocaleString("ar-QA")} ساعة`;
}

export default async function CoursePage({
  params,
}: {
  params: Promise<Params>;
}) {
  const { uuid } = await params;
  const course = await loadCourse(uuid);

  /*
   * The teacher's declared hours, read from the endpoint that already publishes
   * them (`PublicFieldAllowlist::AVAILABILITY`, live since 001). A second copy on
   * the course payload would be a second answer to «متى هو متاح؟» that drifts
   * from the one their own page shows the first time either is edited.
   *
   * A failure here costs the request form and nothing else — the curriculum, the
   * groups and the price are all already loaded, and taking the whole page down
   * over a section a signed-out visitor cannot use is the `Promise.all` defect
   * the dashboard already paid for.
   */
  const availability = await loadAvailability(course);

  const facts = [
    `${course.lessons_count.toLocaleString("ar-QA")} درساً`,
    hours(course.duration_seconds),
    `${course.enrolled_count.toLocaleString("ar-QA")} طالباً`,
  ].filter((fact): fact is string => fact !== null);

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-10 px-4 py-10 sm:px-6">
      <header className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div className="aspect-video w-full shrink-0 overflow-hidden rounded-3xl bg-primary-soft lg:w-80">
          {course.cover_url ? (
            // A plain <img>: `next/image` would route a remote path through
            // `sharp`, whose advisories this tree accepts precisely because no
            // user-supplied image reaches it.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={course.cover_url}
              alt=""
              className="h-full w-full object-cover"
            />
          ) : (
            <span
              className="flex h-full w-full items-center justify-center text-6xl font-black text-primary-ink/30"
              aria-hidden="true"
            >
              {course.title.charAt(0)}
            </span>
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className="rounded-lg bg-primary-soft px-2 py-0.5 font-medium text-primary-ink">
              {TYPE_LABELS[course.type]}
            </span>
            {course.subject && (
              <span className="rounded-lg bg-primary-soft px-2 py-0.5 font-medium text-primary-ink">
                {course.subject.name_ar}
              </span>
            )}
          </div>

          <h1 className="text-2xl font-black leading-tight text-ink sm:text-3xl">
            {course.title}
          </h1>

          <p className="text-sm text-ink-muted">{facts.join(" · ")}</p>

          <StarRating value={course.average_rating} />

          {course.teacher && (
            <Link
              href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}`}
              className="flex w-fit items-center gap-3 rounded-2xl border border-line px-4 py-3 transition hover:border-primary hover:shadow-sm"
            >
              {course.teacher.photo_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={course.teacher.photo_url}
                  alt=""
                  className="h-10 w-10 rounded-full object-cover"
                />
              ) : (
                <span
                  className="flex h-10 w-10 items-center justify-center rounded-full bg-primary-soft font-bold text-primary-ink"
                  aria-hidden="true"
                >
                  {course.teacher.name.charAt(0)}
                </span>
              )}

              <span className="flex flex-col gap-1">
                <span className="text-sm font-bold text-ink">
                  {course.teacher.name}
                </span>
                <TrustScoreBadge
                  score={course.teacher.trust_score}
                  band={course.teacher.trust_score_band}
                />
              </span>
            </Link>
          )}

          {course.price_minor !== null && course.currency && (
            <p className="text-xl font-black text-primary-ink">
              {course.price_minor === 0
                ? "مجاني"
                : formatMinorMoney(course.price_minor, course.currency)}
            </p>
          )}
        </div>
      </header>

      {/*
        The promo video (018 · US1). Mounted only when there is an approved one:
        the button is ABSENT, never disabled — a disabled control promises
        something and then refuses it, leaving the visitor hunting for what she
        did wrong. The server already collapses «no video» and «awaiting review»
        into null, so this page has one condition to read.

        And no booking call to action underneath it: the entrance below has been
        on this page since 023, and a second one is a duplicate of a live door.
      */}
      {course.promo_video_id !== null && (
        <section className="flex flex-col gap-3">
          <PromoVideoButton
            videoId={course.promo_video_id}
            courseTitle={course.title}
          />
        </section>
      )}

      {course.description && (
        <section className="flex flex-col gap-3">
          <h2 className="text-lg font-extrabold text-ink">عن الكورس</h2>
          <p className="whitespace-pre-line text-sm leading-relaxed text-ink-muted">
            {course.description}
          </p>
        </section>
      )}

      {/* ⚠️ THE ANCHOR IS THE INBOUND LINK, NOT DECORATION. The teacher's profile
          lists this teacher's courses and sends each one straight here — a
          student standing on «الجدول» could see the weekly times and had no way
          at all to act on them, three clicks and no signpost away from the only
          two doors that exist. `scroll-mt-24` clears the sticky header, which an
          unmargined anchor lands underneath. */}
      <section id="groups" className="flex scroll-mt-24 flex-col gap-4">
        <h2 className="text-lg font-extrabold text-ink">المجموعات المتاحة</h2>

        {course.cohorts.length > 0 ? (
          <CohortList courseUuid={course.uuid} cohorts={course.cohorts} />
        ) : (
          <EmptyState
            title="لا مواعيد معلَنة بعد"
            description="لم يفتح المدرّس مجموعات لهذا الكورس حتى الآن. تابع صفحته لتعرف حين يفتح موعداً."
          />
        )}
      </section>

      <section className="flex flex-col gap-4">
        <h2 className="text-lg font-extrabold text-ink">حصة خاصة</h2>

        {availability.length > 0 ? (
          <PrivateSessionRequestForm
            courseUuid={course.uuid}
            availability={availability}
            minutes={course.private_session_minutes}
            subscriptionAvailable={course.private_subscription_available}
          />
        ) : (
          <EmptyState
            title="لا مواعيد للحصص الخاصة"
            description="لم يعلن المدرّس مواعيد متاحة بعد. تابع صفحته لتعرف حين يفتح موعداً."
          />
        )}
      </section>

      <section className="flex flex-col gap-4">
        <h2 className="text-lg font-extrabold text-ink">المنهج</h2>

        {course.curriculum.length > 0 ? (
          <CourseCurriculum sections={course.curriculum} />
        ) : (
          <EmptyState
            title="لم تُنشر دروس بعد"
            description="سيظهر محتوى الكورس هنا فور نشر المدرّس أوّل درس."
          />
        )}
      </section>
    </div>
  );
}
