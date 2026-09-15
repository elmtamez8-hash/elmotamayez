import type { Metadata } from "next";
import type { ReactNode } from "react";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { BookIcon, ClockIcon, UsersIcon } from "@/components/icons";
import { CohortList } from "@/components/marketplace/CohortList";
import { CourseCurriculum } from "@/components/marketplace/CourseCurriculum";
import {
  CourseOwnedBadge,
  CourseOwnershipProvider,
} from "@/components/marketplace/CourseOwnership";
import { CourseRail } from "@/components/marketplace/CourseRail";
import { CourseTabs } from "@/components/marketplace/CourseTabs";
import { PrivateSessionRequestForm } from "@/components/courses/PrivateSessionRequestForm";
import { PromoVideoButton } from "@/components/courses/PromoVideoButton";
import { StarRating } from "@/components/marketplace/StarRating";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import {
  NotFoundError,
  publicApi,
  type AvailabilityItem,
  type CourseDetail,
} from "@/lib/public-api";
import { counted, courseTypeLabel } from "@/lib/labels";
import { siteUrl } from "@/lib/site";

type Params = { slug: string };

/**
 * أيقونةٌ من مفرداتِ المشروع — النوعُ مأخوذٌ من المجموعةِ نفسِها لا `ComponentType`.
 *
 * ⚠️ الأيقوناتُ هنا تقبلُ `className` و`title`، و`ComponentType` يقبلُ الأصنافَ
 * أيضاً — فيتّسعُ النوعُ حتى لا يَصِفَ ما يُمرَّرُ فعلاً، ويسقطُ `tsc` عندَ أوّلِ
 * مُسنَدٍ لا يطابقُ التوقيع.
 */
type Glyph = typeof BookIcon;

/*
 * ⚠️ THE ADDRESS IS THE SLUG NOW, AND THE UUID STILL OPENS IT.
 *
 * This said the opposite until 2026-09-06, and it was true when written:
 * `courses.slug` was unique per (workspace_id, slug) — inside one workspace only
 * — so two teachers naming a course «الرياضيات ٣» produced the same slug and a
 * public route with no workspace to read could not tell them apart. The index is
 * platform-wide now, the same key `/teachers/{slug}` has carried since 2026-08.
 *
 * The uuid resolves and 308s to the slug, because every link shared before today
 * is a uuid and serving one page at two addresses splits its ranking between
 * them.
 */
async function loadCourse(key: string): Promise<CourseDetail> {
  try {
    const { data } = await publicApi.course(key);

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
  const { slug } = await params;

  try {
    const course = await loadCourse(slug);

    return {
      title: course.teacher
        ? `${course.title} — ${course.teacher.name}`
        : course.title,
      description:
        course.description?.slice(0, 155) ??
        `${course.title}: ${counted(course.lessons_count, {
          one: "درس واحد",
          two: "درسان",
          few: "دروس",
          many: "درساً",
          other: "درس",
        })} على منصّتنا.`,
      // Absolute, for the reason the home page's is: a relative canonical
      // resolves against whichever host the crawler arrived on.
      alternates: { canonical: siteUrl(`/courses/${course.slug ?? course.uuid}`) },
    };
  } catch {
    return { title: "غير متاح" };
  }
}

/**
 * The teacher's declared hours — or `null`, meaning the question was not
 * answered at all.
 *
 * ⚠️ A SWALLOWED ERROR AND AN EMPTY LIST USED TO READ THE SAME. This returned
 * `[]` from its own `catch`, so a stumbling API drew «لم يعلن المدرّس مواعيد
 * متاحة بعد» — a sentence stating, as a fact about the teacher, something the
 * page never found out. The student then waits for an announcement that was
 * made weeks ago, and no refresh ever suggests itself.
 *
 * A 404 is deliberately NOT that case: it is an ANSWER (the teacher is not
 * publicly listed), no retry can change it, and offering «إعادة المحاولة» over
 * it promises something impossible.
 */
async function loadAvailability(
  course: CourseDetail,
): Promise<AvailabilityItem[] | null> {
  const key = course.teacher?.slug ?? course.teacher?.uuid;

  if (key === undefined) return [];

  try {
    const { data } = await publicApi.teacher(key);

    return data.availability;
  } catch (error) {
    if (error instanceof NotFoundError) return [];

    return null;
  }
}

function hours(seconds: number): string | null {
  const value = Math.round(seconds / 3600);

  if (value <= 0) return null;

  return counted(value, {
    one: "ساعة",
    two: "ساعتان",
    few: "ساعات",
    many: "ساعة",
    other: "ساعة",
  });
}

export default async function CoursePage({
  params,
}: {
  params: Promise<Params>;
}) {
  const { slug } = await params;
  const course = await loadCourse(slug);

  /*
   * 308 to the canonical slug when the visitor arrived on the old uuid URL —
   * the same block `teachers/[slug]` has carried since 2026-08, and for the same
   * reason: serving one course at two addresses splits its ranking between them,
   * so the search engine has to be told which of the two to keep.
   *
   * ⚠️ COMPARED AFTER DECODING. A slug is ASCII today, but the comparison is
   * what decides whether a redirect fires, and an encoded segment that never
   * equals its own decoded form is an infinite redirect to itself.
   */
  if (course.slug && decodeURIComponent(slug) !== course.slug) {
    permanentRedirect(`/courses/${course.slug}`);
  }

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

  /*
   * ⚠️ الصفرُ يسقطُ من الشريطِ ولا يُنطَق. `hours()` تُعيدُ `null` عندَ الصفرِ
   * منذُ كُتِبَت، ولنفسِ السبب: «لا طلاب» على كورسٍ جديدٍ إعلانٌ ضدَّ صاحبِه،
   * و«٠ طالباً» — وهو ما كان يُطبَع — أسوأُ منه.
   */
  const facts = [
    course.lessons_count > 0 && {
      key: "lessons",
      Icon: BookIcon,
      text: counted(course.lessons_count, {
        one: "درس واحد",
        two: "درسان",
        few: "دروس",
        many: "درساً",
        other: "درس",
      }),
    },
    hours(course.duration_seconds) !== null && {
      key: "hours",
      Icon: ClockIcon,
      text: hours(course.duration_seconds) as string,
    },
    course.enrolled_count > 0 && {
      key: "students",
      Icon: UsersIcon,
      text: counted(course.enrolled_count, {
        one: "طالب واحد",
        two: "طالبان",
        few: "طلاب",
        many: "طالباً",
        other: "طالب",
      }),
    },
  ].filter(
    (fact): fact is { key: string; Icon: Glyph; text: string } =>
      typeof fact === "object",
  );

  return (
    /*
      ⛔ THE PROVIDER WRAPS THE WHOLE PAGE BECAUSE TWO PLACES ASK ONE QUESTION —
      the rail and the syllabus — and the badge on the cover is a third. A fetch
      per consumer is the same request three times on a page most readers open
      signed out. See `CourseOwnership` for why a SERVER page needs this at all.
    */
    <CourseOwnershipProvider courseUuid={course.uuid}>
      <div className="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        {/*
          الغلافُ شريطٌ عريضٌ لا مربّعٌ جانبيّ: صفحةُ الكورسِ تُفتَحُ لقرار،
          وأوّلُ ما يُرى يجبُ أن يكونَ الكورسَ نفسَه.
        */}
        <div className="relative aspect-[21/9] w-full overflow-hidden rounded-3xl bg-primary sm:aspect-[21/7]">
          {course.cover_url ? (
            // A plain <img>: `next/image` would route a remote path through
            // `sharp`, whose advisories this tree accepts precisely because no
            // user-supplied image reaches it.
            // eslint-disable-next-line @next/next/no-img-element
            <img src={course.cover_url} alt="" className="h-full w-full object-cover" />
          ) : (
            <span
              className="flex h-full w-full items-center justify-center text-7xl font-black text-white/85"
              aria-hidden="true"
            >
              {course.title.charAt(0)}
            </span>
          )}

          <CourseOwnedBadge />
        </div>

        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
          <div className="flex min-w-0 flex-col gap-9">
            <header className="flex flex-col gap-4">
              <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="rounded-lg bg-primary-soft px-2.5 py-1 font-semibold text-primary-ink">
                  {courseTypeLabel(course.type)}
                </span>
                {course.subject && (
                  <span className="rounded-lg bg-primary-soft px-2.5 py-1 font-semibold text-primary-ink">
                    {course.subject.name}
                  </span>
                )}
              </div>

              <h1 className="text-balance text-3xl font-black leading-tight text-ink sm:text-4xl">
                {course.title}
              </h1>

              {/* ⚠️ الحقيقةُ أيقونةٌ وكلمة، والصفرُ يسقطُ ولا يُنطَق — انظر `facts`. */}
              {facts.length > 0 && (
                <ul className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-ink-muted">
                  {facts.map(({ key, Icon, text }) => (
                    <li key={key} className="flex items-center gap-1.5">
                      <span aria-hidden="true">
                        <Icon />
                      </span>
                      <bdi>{text}</bdi>
                    </li>
                  ))}
                </ul>
              )}

              <StarRating value={course.average_rating} />
            </header>

            {/*
              The promo video (018 · US1). Mounted only when there is an approved
              one: the button is ABSENT, never disabled — a disabled control
              promises something and then refuses it, leaving the visitor hunting
              for what she did wrong. The server already collapses «no video» and
              «awaiting review» into null, so this page has one condition to read.

              And no booking call to action underneath it: the entrance below has
              been on this page since 023, and a second one is a duplicate of a
              live door.
            */}
            {course.promo_video_id !== null && (
              <section className="flex flex-col gap-3">
                <PromoVideoButton videoId={course.promo_video_id} courseTitle={course.title} />
              </section>
            )}

            {/*
              ⚠️ ثلاثةُ أقسامٍ صارت شريطاً واحداً، والمنهجُ **بقيَ تحتَه مفتوحاً**.
              الثلاثةُ أجوبةٌ قصيرةٌ عن «ما هو» و«متى» و«وحدي؟» يُقرَأُ منها واحدٌ
              في المرّة؛ والمنهجُ هو ما تُفتَحُ الصفحةُ لأجلِه، ودفنُه خلفَ نقرةٍ
              يجعلُ أطولَ قسمٍ وأهمَّه أقلَّها ظهوراً.

              و`#groups` — الرابطُ الذي تُلصِقُه صفحةُ المدرّسِ بكلِّ بطاقةِ كورس —
              يفتحُ تبويبَ المجموعات، فلا يصيرُ مرساةً إلى لوحٍ مخفيّ.
            */}
            <CourseTabs
              groupCount={course.cohorts.length}
              about={
                course.description === null ? null : (
                  <p className="max-w-[62ch] whitespace-pre-line text-[0.95rem] leading-loose text-ink-muted">
                    {course.description}
                  </p>
                )
              }
              groups={
                course.cohorts.length > 0 ? (
                  <CohortList
                    courseUuid={course.uuid}
                    cohorts={course.cohorts}
                    isFull={course.is_full}
                  />
                ) : (
                  <EmptyState
                    title="لا مواعيد معلَنة بعد"
                    description="لم يفتح المدرّس مجموعات لهذا الكورس حتى الآن. تابع صفحته لتعرف حين يفتح موعداً."
                  />
                )
              }
              privateSession={
                availability === null ? (
                  /*
                   * ⚠️ NOT `ErrorState`'S DEFAULT COPY. It says «تحقّق من اتصالك»,
                   * and this fetch happened on the SERVER — the visitor's own
                   * connection demonstrably works, they are reading the page it
                   * produced.
                   */
                  <ErrorState
                    title="تعذّر تحميل مواعيد المدرّس"
                    description="حدث خطأ أثناء جلب المواعيد المتاحة. أعد المحاولة بعد قليل."
                  />
                ) : availability.length > 0 ? (
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
                )
              }
            />

            <section className="flex flex-col gap-4">
              <SectionHeading Icon={BookIcon}>المنهج</SectionHeading>

              {course.curriculum.length > 0 ? (
                <CourseCurriculum
                  sections={course.curriculum}
                  courseSlug={course.slug ?? undefined}
                />
              ) : (
                <EmptyState
                  title="لم تُنشر دروس بعد"
                  description="سيظهر محتوى الكورس هنا فور نشر المدرّس أوّل درس."
                />
              )}
            </section>
          </div>

          {/* ⚠️ لاصقٌ على الشاشاتِ الواسعةِ وحدَها. عمودٌ لاصقٌ على الهاتفِ يأكلُ
              نصفَ الشاشة، فيهبطُ هنا إلى مكانِه في التدفّقِ ويُمرَّرُ كأيِّ قسم. */}
          <div className="lg:sticky lg:top-24">
            <CourseRail
              priceMinor={course.price_minor}
              currency={course.currency ?? null}
              courseUuid={course.uuid}
              isFull={course.is_full}
              teacher={course.teacher}
            />
          </div>
        </div>
      </div>
    </CourseOwnershipProvider>
  );
}

/**
 * عنوانُ قسمٍ بأيقونتِه.
 *
 * ⚠️ الأيقونةُ `aria-hidden` دائماً: الكلمةُ إلى جانبِها تقولُ ما تقولُه، وإعلانُها
 * مرّتَينِ ضجيجٌ على قارئِ الشاشة — قاعدةُ ملفِّ الأيقوناتِ نفسِها.
 */
function SectionHeading({ Icon, children }: { Icon: Glyph; children: ReactNode }) {
  return (
    <h2 className="flex items-center gap-2.5 text-lg font-extrabold text-ink">
      <span className="text-primary-ink" aria-hidden="true">
        <Icon />
      </span>
      {children}
    </h2>
  );
}
