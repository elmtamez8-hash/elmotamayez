import {
  AcademicCapIcon,
  CheckIcon,
  CoursesIcon,
  LearningIcon,
  MessagesIcon,
  ProgressIcon,
  QuestionIcon,
  SessionsIcon,
  StudentIcon,
  UserIcon,
  type IconProps,
} from "@/components/icons";
import type { ComponentType } from "react";
import { subjectIcon } from "@/components/marketplace/subject-icon";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { publicApi, NotFoundError, type TeacherDetail } from "@/lib/public-api";
import {
  AvailableNowChip,
  AvailableNowDot,
} from "@/components/marketplace/AvailableNow";
import { StarRating } from "@/components/marketplace/StarRating";
import { TrialCta } from "@/components/marketplace/TrialCta";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { TrustScoreBreakdown } from "@/components/marketplace/TrustScoreBreakdown";
import { AvailabilityCalendar } from "@/components/marketplace/AvailabilityCalendar";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { ReviewsTab } from "@/components/marketplace/ReviewsTab";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { videoEmbedUrl } from "@/lib/video-embed";
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
  /* ⚠️ أيقونةٌ لكلِّ رقمٍ من الطقمِ القائم، ولا رسمَ يُخترَعُ للمناسبة: أربعُ
     أيقوناتٍ متقاربةٌ أسوأُ من أربعةِ عناوين — قاعدةُ `AXIS_ICONS` نفسُها. وهي
     `aria-hidden` بالبناءِ من `wrap()`، فالتسميةُ تحتَها هي النصُّ ولا يُقرَأُ
     الرمزُ مرّتَين. */
  const items: Array<{
    label: string;
    value: number | null;
    suffix?: string;
    Icon: ComponentType<IconProps>;
  }> = [
    { label: "طالب درّسهم", value: stats.students_taught, Icon: StudentIcon },
    { label: "حصة مكتملة", value: stats.completed_sessions, Icon: SessionsIcon },
    { label: "معدّل الاستجابة", value: stats.response_rate, suffix: "٪", Icon: MessagesIcon },
    { label: "نسبة الحضور", value: stats.attendance_rate, suffix: "٪", Icon: ProgressIcon },
  ];

  // Four bordered boxes of big-number-small-label is the hero-metric template,
  // and it was standing inside the «نبذة» tab as if these facts were part of the
  // biography. They are facts about the teacher whichever tab is open, so they
  // sit under the masthead — as a rule of figures separated by dividers, which
  // is what a row of related numbers looks like when it is not four cards.
  return (
    <dl className="grid grid-cols-2 gap-x-8 gap-y-5 sm:gap-x-12">
      {items.map(({ label, value, suffix, Icon }) => (
        <div key={label} className="flex items-center gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
            <Icon className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <dd className="text-2xl font-extrabold leading-none text-primary-ink">
              <bdi>
                {value === null ? "—" : `${value.toLocaleString("ar-QA")}${suffix ?? ""}`}
              </bdi>
            </dd>
            <dt className="mt-1 text-xs text-ink-muted">{label}</dt>
          </div>
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
      <header className="banner-rise mb-8 flex flex-col gap-8 rounded-3xl border border-line bg-surface-raised p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-col gap-6 sm:flex-row">
          {/* ⚠️ `relative` AND `shrink-0` ON THE WRAPPER, not on the photo: the
              dot is positioned against this box, and the box is what has to hold
              its width in the flex row. */}
          <div className="relative shrink-0 self-start">
            {teacher.photo_url ? (
              <img
                src={teacher.photo_url}
                alt=""
                className="h-32 w-32 rounded-2xl object-cover ring-1 ring-line"
              />
            ) : (
              <span
                className="flex h-32 w-32 items-center justify-center rounded-2xl bg-primary-soft text-4xl font-bold text-primary-ink ring-1 ring-line"
                aria-hidden="true"
              >
                {teacher.name.charAt(0)}
              </span>
            )}

            {teacher.available_now && <AvailableNowDot />}
          </div>

          <div>
            <h1 className="mb-2 flex flex-wrap items-center gap-2 text-2xl font-extrabold text-ink sm:text-3xl">
              {teacher.name}
              {teacher.is_verified && (
                <span className="inline-flex items-center gap-1 rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary-ink">
                  <CheckIcon className="h-3.5 w-3.5" />
                  موثّق
                </span>
              )}
              {/* Beside the name, where a status about a person belongs — it used
                  to sit at the bottom of the booking panel, below two buttons and
                  off the first screen on a phone. */}
              {teacher.available_now && <AvailableNowChip />}
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
                {teacher.subjects.map((subject) => {
                  /* ⚠️ الخريطةُ المشتركةُ نفسُها التي ترسمُ بها شبكةُ الموادِّ في
                     الصفحةِ الأولى — لا نسخةٌ ثانيةٌ هنا: خريطتانِ تفترقانِ عندَ
                     أوّلِ مادّةٍ تُضاف، فترسمُ الشبكةُ رمزَ الفيزياءِ وترسمُ
                     الشريحةُ قبّعةَ تخرّج، بلا خطأٍ في أيِّ مكان. */
                  const Icon = subjectIcon(subject);

                  return (
                    <li key={subject.slug}>
                      <Link
                        href={`/teachers?subject=${subject.slug}`}
                        className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1.5 text-sm font-medium text-primary-ink transition duration-200 ease-out hover:brightness-95 active:scale-[0.97] active:duration-100"
                      >
                        <Icon className="h-4 w-4" />
                        {subject.name}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            )}

            {/* The stage was published by the API and shown nowhere. It is the
                second thing a parent checks after the subject — «ثانوي» decides
                whether this teacher is relevant at all. */}
            {teacher.grade_levels.length > 0 && (
              <p className="flex items-center gap-2 text-sm text-ink-muted">
                <AcademicCapIcon className="h-4 w-4 shrink-0 text-primary-ink" />
                <span>
                  يدرّس{" "}
                  {teacher.grade_levels.map((level) => level.name).join(" · ")}
                </span>
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

      {/* ⚠️ NO `items-start` HERE, AND THAT IS THE WHOLE OF FR-054 ON DESKTOP.
          `position: sticky` travels inside its containing block and nowhere else,
          so a grid item shrunk to its own content height gives the panel inside
          it exactly zero room to move: it reads as sticky, scrolls away with the
          page, and the desktop profile ends with no booking CTA on screen — the
          one thing FR-054 forbids, and the reason a fixed bar exists below `lg`.
          Stretching (the grid default) makes the aside as tall as the tabs beside
          it, which is the runway the sticky panel needs. Invisible either way:
          the aside paints nothing of its own. `discovery.spec.ts` measures it,
          because no unit test can see a computed layout. */}
      <div className="grid gap-8 lg:grid-cols-[1fr_320px]">
        {/* Sticky booking panel (FR-054): spans both content rows so it stays put
            while the tabs scroll. On mobile it sits between the identity block and
            the tabs, which is where a price belongs on a phone. */}
        {/* ⚠️ الحركةُ على عمودَي الشبكةِ لا على كلِّ بطاقةٍ داخلَهما: تأخيرٌ
            متدرّجٌ لعشراتِ العناصرِ يجعلُ آخرَها يصلُ بعدَ ثانيةٍ ونصف، والصفحةُ
            تُقرَأُ وهي ما تزالُ تتجمّع. عنصرانِ وتأخيرٌ واحدٌ يكفيان.
            و`banner-rise` صنفٌ قائمٌ في `globals.css` وكتلةُ
            `prefers-reduced-motion` هناك تُصفِّرُه. */}
        <aside className="banner-rise lg:col-start-2 lg:row-start-1" style={{ animationDelay: "80ms" }}>
          {/* ⚠️ THE BOOKING CARD IS ALONE IN HERE, AND THAT IS WHAT MAKES THE
              STICKY WORK. `position: sticky` travels inside its containing block
              and nowhere else, so the size of this column against the one beside
              it is the whole mechanism — measured, because no unit test can see a
              computed layout:

                aside 823px · tabs shorter · page 1730px · viewport 900px
                → the sticky box FILLED the row it was meant to travel in,
                  moved zero pixels, and at the bottom of the page the last
                  384px of the grid held the trust breakdown while the CTA sat
                  338px above the fold. FR-054 asks for a booking button that
                  stays visible while scrolling; there was none.

              Two things were wrong and each hid the other. `lg:items-start` on
              the grid shrank this column to its content, so there was no runway
              at all; and the breakdown was IN here, which made this column the
              taller of the two — so stretching alone still gave zero travel.
              The breakdown now lives under the tabs, and this card is ~250px in
              an 823px column: it pins at `top-24` and is still on screen when the
              grid ends.

              It also settles an older bug for free. The breakdown used to sit
              directly under a sticky card, and a sticky box paints in normal
              order — so on the way past it slid up and covered the CTA. Sticking
              the PAIR fixed the overlap and caused the zero-travel above. With
              nothing after this card in the column, neither can happen. */}
          <div className="lg:sticky lg:top-24">
            <div className="rounded-3xl border border-line bg-surface-raised p-6">
              {/* ⚠️ The price is gone from this panel (spec 006, FR-021و · FR-021هـ).
                It is not hidden pending a redesign: the platform is the seller
                now, the student's total is computed per package on the purchase
                screen, and the teacher's own rate is what they are PAID — a
                number FR-021ب keeps off every student-facing surface.

                The panel keeps its job. What sold the booking was never the
                number; it was knowing who this teacher is, which is what stands
                here instead. */}
              <p className="mb-1 flex items-center gap-2 text-sm text-ink-muted">
                <SessionsIcon className="h-4 w-4 text-primary-ink" />
                الحجز مع
              </p>
              <p className="mb-5 text-2xl font-extrabold text-ink">
                {teacher.name}
              </p>

              {/*
                ⚠️ ONE CONTROL NOW, NOT TWO — and the pair was the defect. Both
                pointed at `/signup/student`, so a signed-in visitor of ANY role
                was offered a student registration form twice on the same panel.
                `TrialCta` renders them for a guest and replaces them with the
                way into the reader's own panel otherwise; there is no
                trial-booking flow for an existing account to send them to yet.
              */}
              <TrialCta teacherUuid={teacher.uuid} variant="profile" />
            </div>

          </div>
        </aside>

        <div className="banner-rise lg:col-start-1 lg:row-start-1" style={{ animationDelay: "140ms" }}>
          <ProfileTabs slug={teacher.slug ?? teacher.uuid} active={active}>
            {active === "about" && (
              <div>
                <div className="space-y-8">
                  {/*
                    ⚠️ **`videoEmbedUrl` هو الحدّ، لا القاعدةُ على الخادم.** الرابطُ
                    نصٌّ حرٌّ كتبَه المدرّسُ، وهذه الصفحةُ يفتحُها كلُّ زائر — فتمريرُه
                    إلى `src` كما هو يجعلُ من حقلٍ في «ملفّي» باباً يُشغِّلُ ما يشاءُ
                    في متصفِّحِ من يقرأ. الدالّةُ تستخرجُ المعرِّفَ وتبني العنوانَ من
                    ثوابتِنا، وتُعيدُ `null` لما لا تفهمُه — فيختفي القسمُ كلُّه.

                    وفوقَ النبذةِ عمداً: وجهٌ وصوتٌ لدقيقةٍ يقولانِ عن مدرّسٍ ما لا
                    تقولُه فقرة، وهو أوّلُ ما يبحثُ عنه وليُّ أمرٍ يختارُ لابنِه.
                  */}
                  {videoEmbedUrl(teacher.intro_video_url) !== null && (
                    <section aria-labelledby="intro-video-heading">
                      <h2
                        id="intro-video-heading"
                        className="mb-3 flex items-center gap-2 text-lg font-bold text-ink"
                      >
                        <SessionsIcon className="h-5 w-5 text-primary-ink" />
                        فيديو تعريفي
                      </h2>
                      {/* نسبةُ ١٦:٩ بالصنفِ القائم، فلا يقفزُ التخطيطُ عندَ التحميل. */}
                      <div className="aspect-video overflow-hidden rounded-2xl border border-line bg-surface-raised">
                        <iframe
                          src={videoEmbedUrl(teacher.intro_video_url) ?? undefined}
                          title={`فيديو تعريفي عن ${teacher.name}`}
                          loading="lazy"
                          allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                          allowFullScreen
                          className="h-full w-full"
                        />
                      </div>
                    </section>
                  )}

                  <section aria-labelledby="bio-heading">
                    {/* ⚠️ الرمزُ داخلَ العنوانِ لا بجوارَه في صفٍّ ثانٍ: عنوانٌ
                        ورمزٌ في عنصرَينِ متجاورَينِ يفترقانِ عندَ أوّلِ التفافِ
                        سطر. */}
                    <h2
                      id="bio-heading"
                      className="mb-3 flex items-center gap-2 text-lg font-bold text-ink"
                    >
                      <UserIcon className="h-5 w-5 text-primary-ink" />
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
                        className="mb-3 flex items-center gap-2 text-lg font-bold text-ink"
                      >
                        <LearningIcon className="h-5 w-5 text-primary-ink" />
                        المؤهلات والشهادات
                      </h2>
                      <ul className="space-y-2">
                        {teacher.qualifications.map((qualification) => (
                          <li
                            key={qualification}
                            className="flex items-start gap-2.5 rounded-xl border border-line px-3 py-2.5 text-sm text-ink-muted"
                          >
                            <CheckIcon className="mt-0.5 h-4 w-4 shrink-0 text-secondary-ink" />
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
                /* ⚠️ THE OLD SENTENCE HERE PROMISED A CONTROL THAT DOES NOT
                   EXIST: «يمكنك حجز حصة فردية معه مباشرة عبر زر الحجز» — the
                   panel's only button is `TrialCta`, which sends a guest to the
                   student signup and a signed-in reader to their own panel.
                   Neither books anything. And a teacher with no published course
                   has no private-session door either, because a private
                   subscription is bought INSIDE a course. */
                <EmptyState
                  title="لا توجد كورسات منشورة لهذا المدرّس"
                  description="لم ينشر هذا المدرّس كورساً بعد، والاشتراك — بمجموعة أو بحصص خاصة — يكون داخل كورس، فلا سبيل إليه حتى ينشر واحداً."
                />
              ) : (
                <div className="grid gap-6 sm:grid-cols-2">
                  {teacher.courses.map((course) => (
                    <CourseCard key={course.uuid} course={course} />
                  ))}
                </div>
              ))}

            {active === "reviews" && (
              <ReviewsTab
                teacherUuid={teacher.uuid}
                reviews={teacher.reviews}
              />
            )}

            {active === "schedule" && (
              <div className="space-y-10">
                <AvailabilityCalendar slots={teacher.availability} />

                {/* ⚠️ THE CALENDAR ABOVE IS A DISPLAY, AND ON ITS OWN IT MADE A
                    PROMISE THE PAGE DID NOT KEEP. A student reading a teacher's
                    weekly times is one step from asking for one of them — and
                    this tab offered no control at all: the panel's only button
                    is the trial signup, and both subscription doors («اشترك في
                    هذه المجموعة» · «اشترك بحصص خاصة») live on a COURSE page,
                    three unsignposted clicks away.

                    A subscription is bought per course — the plan covers one,
                    the group belongs to one, and even a private session is
                    requested inside one — so the courses are the honest step
                    between a time and a booking. Each card lands directly on
                    that course's «المجموعات المتاحة», where the open groups
                    carry their button and a full one carries none.

                    ⚠️ AND NOTHING IS FILTERED HERE. Which groups are joinable is
                    `CohortList`'s answer, computed server-side from
                    `is_joinable`; re-deriving it in TypeScript is the two
                    spellings of one question that made a paid-for recording
                    unreachable in 018. */}
                {teacher.courses.length > 0 && (
                  <section aria-labelledby="book-heading" className="space-y-4">
                    <div>
                      <h2
                        id="book-heading"
                        className="flex items-center gap-2 text-lg font-extrabold text-ink"
                      >
                        <CoursesIcon className="h-5 w-5 text-primary-ink" />
                        احجز مع {teacher.name}
                      </h2>
                      <p className="mt-1 text-sm text-ink-muted">
                        اختر كورساً لترى مجموعاته المتاحة ومواعيدها، أو لتطلب حصة
                        خاصة فيه.
                      </p>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                      {teacher.courses.map((course) => (
                        <CourseCard
                          key={course.uuid}
                          course={course}
                          anchor="#groups"
                        />
                      ))}
                    </div>
                  </section>
                )}
              </div>
            )}
            {active === "faq" && (
              <div className="space-y-4">
                <div>
                  <h2 className="flex items-center gap-2 text-lg font-extrabold text-ink">
                    <QuestionIcon className="h-5 w-5 text-primary-ink" />
                    أسئلة شائعة عن {teacher.name}
                  </h2>
                  <p className="mt-1 text-sm text-ink-muted">
                    كتبها المدرّس بنفسه رداً على ما يسأله الطلاب وأولياء الأمور
                    عادةً.
                  </p>
                </div>

                {teacher.faqs.length === 0 ? (
                  <EmptyState
                    title="لم يضف هذا المدرّس أسئلة شائعة بعد"
                    description="يمكنك سؤاله مباشرة بعد الاشتراك في أحد كورساته."
                  />
                ) : (
                  <FaqAccordion items={teacher.faqs} />
                )}
              </div>
            )}
          </ProfileTabs>

          {/* ⚠️ UNDER THE TABS, NOT IN THE BOOKING COLUMN — and that placement is
              load-bearing, not tidying. FR-024 and the product's third
              differentiator make the visible factor breakdown matter, and it was
              put beside the booking panel so it would land on the first screen of
              desktop. The cost was invisible until it was measured: two cards in
              that column made it 823px, taller than the tabs, which left the
              sticky booking panel with zero travel and took the only desktop CTA
              off screen at the bottom of every profile (FR-054).

              It is also not going back into the content column as a nested
              380px box — that is where it started, and it squeezed the biography
              to about 440px and read as the page's subject. Full width under the
              tabs is neither: the decision is still one scroll away, and the
              booking button that FR-054 is actually about now stays put. */}
          <div className="mt-12">
            <TrustScoreBreakdown
              score={teacher.trust_score}
              band={teacher.trust_score_band}
              factors={teacher.trust_score_factors}
            />
          </div>

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
            {teacher.subjects[0]?.name ?? "حصص خاصة"}
          </p>
          {/* ⚠️ THE THIRD CALL SITE, AND THE ONE THAT ONLY APPEARS ON A PHONE.
              Two of them were fixed and this one sat under `lg:hidden`, so the
              defect survived on exactly the screen the redesign is aimed at.
              Grep for the route, never for the button's label. */}
          <TrialCta teacherUuid={teacher.uuid} variant="bar" />
        </div>
      </div>
    </div>
  );
}
