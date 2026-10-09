import { ContactTeacherButton } from "@/components/community/ContactTeacherButton";
import { ViewableImage } from "@/components/ui/ImageLightbox";
import {
  AcademicCapIcon,
  CheckIcon,
  VerifiedBadgeIcon,
  CoursesIcon,
  LearningIcon,
  MessagesIcon,
  OrdersIcon,
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
import { StickyCtaBar } from "@/components/ui/StickyCtaBar";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { TrustScoreBreakdown } from "@/components/marketplace/TrustScoreBreakdown";
import { AvailabilityCalendar } from "@/components/marketplace/AvailabilityCalendar";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { PublicStoreCard } from "@/components/store/PublicStoreCard";
import { ReviewsTab } from "@/components/marketplace/ReviewsTab";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { videoEmbedUrl } from "@/lib/video-embed";
import { arabicNumber } from "@/lib/numerals";
import { counted, YEARS_OF_EXPERIENCE } from "@/lib/labels";
import { platformName } from "@/lib/platform";
import { siteUrl } from "@/lib/site";
import { JsonLd, absoluteHttpUrl } from "@/components/seo/JsonLd";
import { personLd } from "@/lib/structured-data";
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
    const title = `${teacher.name} — ${teacher.headline ?? "مدرّس"}`;
    const description =
      teacher.bio?.slice(0, 155) ??
      `احجز حصة مع ${teacher.name}، ${counted(teacher.years_experience, YEARS_OF_EXPERIENCE)}.`;
    // Absolute and on the slug, for the reason the course page's is: a relative
    // canonical resolves against whichever host the crawler arrived on, and the
    // uuid address 308s to this one. Encoded because a slug may be Arabic.
    const url = siteUrl(`/teachers/${encodeURIComponent(teacher.slug ?? teacher.uuid)}`);

    return {
      title,
      description,
      alternates: { canonical: url },
      openGraph: {
        title,
        description,
        url,
        type: "profile",
        locale: "ar_QA",
        siteName: await platformName(),
        /*
         * ⚠️ الصورةُ الشخصيّةُ إن كانت عنواناً مطلقاً، وصورةُ القسمِ وإلّا —
         * بطاقةُ مشاركةٍ بلا صورةٍ شريطٌ رماديٌّ في كلِّ تطبيقِ محادثة، والرابطُ
         * النسبيُّ لا يقرؤه أيٌّ منها.
         */
        images: [
          {
            url:
              absoluteHttpUrl(teacher.photo_url) ??
              siteUrl("/marketplace/banner-teachers.webp"),
          },
        ],
      },
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
        <div key={label} className="group flex items-center gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-200 ease-out group-hover:bg-primary group-hover:text-white motion-reduce:transition-none">
            <Icon className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <dd className="text-3xl font-extrabold leading-none text-primary-ink">
              <bdi>
                {value === null ? "—" : `${arabicNumber(value)}${suffix ?? ""}`}
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
  // Best-effort: the profile renders without its shelf rather than failing.
  const storeItems = await publicApi
    .storeItems({ teacher: teacher.uuid })
    .then((page) => page.data)
    .catch(() => []);
  const active: ProfileTabId = isProfileTab(tab) ? tab : "about";
  // The shared subject map, as the chips below use it — never a second one.
  const CoverIcon = teacher.subjects[0] ? subjectIcon(teacher.subjects[0]) : null;

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
      {/* The same canonical `generateMetadata` names — slug, encoded. */}
      <JsonLd
        data={personLd(
          teacher,
          siteUrl(`/teachers/${encodeURIComponent(teacher.slug ?? teacher.uuid)}`),
        )}
      />
      {/*
        | The masthead spans the page; the two-column grid starts BELOW it.
        |
        | It used to be the first cell of a `[1fr_320px]` grid, which capped the
        | teacher's name, headline, rating and subjects at two thirds of the
        | width while a booking box with two buttons held the other third at the
        | top of the page. The one thing every visitor is here to read was the
        | narrower of the two.
      */}
      <header className="banner-rise mb-8 overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm">
        {/* The cover: the brand colour with the wordmark's square dots, and the
            teacher's first subject drawn large and faint at its end. Decorative
            only — nothing a reader needs is written on burgundy here, so every
            chip below (trust, availability, verified) keeps the surface its
            colours were measured against. */}
        <div
          className="bg-squares relative isolate h-28 overflow-hidden bg-primary sm:h-36"
          aria-hidden="true"
        >
          {CoverIcon && (
            <CoverIcon className="absolute -bottom-10 end-6 -z-10 h-48 w-48 text-white/10 sm:h-60 sm:w-60" />
          )}
        </div>

        <div className="flex flex-col gap-8 px-6 pb-8 sm:px-8 lg:flex-row lg:items-end lg:justify-between">
        <div className="flex flex-col gap-6 sm:flex-row">
          {/* ⚠️ `relative` AND `shrink-0` ON THE WRAPPER, not on the photo: the
              dot is positioned against this box, and the box is what has to hold
              its width in the flex row. */}
          <div className="relative -mt-16 shrink-0 self-start">
            {teacher.photo_url ? (
              // Pressed, the photo opens in the page's viewer at full size.
              <ViewableImage src={teacher.photo_url} alt={`صورة ${teacher.name}`}>
                <img
                  src={teacher.photo_url}
                  alt=""
                  className="h-32 w-32 rounded-3xl object-cover shadow-lg shadow-primary/20 ring-4 ring-surface-raised"
                />
              </ViewableImage>
            ) : (
              <span
                className="flex h-32 w-32 items-center justify-center rounded-3xl bg-primary-soft text-5xl font-extrabold text-primary-ink shadow-lg shadow-primary/20 ring-4 ring-surface-raised"
                aria-hidden="true"
              >
                {teacher.name.charAt(0)}
              </span>
            )}

            {teacher.available_now && <AvailableNowDot />}
          </div>

          <div className="sm:pt-5">
            <h1 className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-3xl font-extrabold text-balance text-ink sm:text-4xl">
              {teacher.name}
              {teacher.is_verified && (
                <span className="inline-flex items-center gap-1 rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary-ink">
                  <VerifiedBadgeIcon className="h-4 w-4" />
                  موثّق
                </span>
              )}
              {/* Beside the name, where a status about a person belongs — it used
                  to sit at the bottom of the booking panel, below two buttons and
                  off the first screen on a phone. */}
              {teacher.available_now && <AvailableNowChip />}
            </h1>

            <p className="mb-4 max-w-2xl text-lg leading-relaxed text-ink-muted">{teacher.headline}</p>

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
                        className="group/chip inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1.5 text-sm font-semibold text-primary-ink transition duration-200 ease-out hover:-translate-y-0.5 hover:bg-primary hover:text-white hover:shadow-md hover:shadow-primary/20 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0"
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

            {/* «تواصل مع المدرّس» (2026-09-28): the private conversation with
                this teacher's side, opened by its first message. */}
            {teacher.contact !== null && teacher.contact !== undefined && (
              <div className="mt-4">
                <ContactTeacherButton
                  workspaceUuid={teacher.contact.workspace_uuid}
                  contactName={teacher.contact.name}
                />
              </div>
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
      {/* `grid-cols-1` is `minmax(0,1fr)`: without it the one implicit column
          on a phone grows to the tab strip's full width and the page scrolls
          sideways (measured 563px on a 360px screen). */}
      <div className="grid grid-cols-1 gap-8 lg:grid-cols-[1fr_320px]">
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
            <div className="overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-lg shadow-primary/10">
              {/* ⚠️ The price is gone from this panel (spec 006, FR-021و · FR-021هـ).
                It is not hidden pending a redesign: the platform is the seller
                now, the student's total is computed per package on the purchase
                screen, and the teacher's own rate is what they are PAID — a
                number FR-021ب keeps off every student-facing surface.

                The panel keeps its job. What sold the booking was never the
                number; it was knowing who this teacher is, which is what stands
                here instead. */}
              {/* The burgundy head is the page's one loud panel: the place the
                  eye returns to while the tabs scroll beside it. White on
                  `bg-primary` only — the chips that need the light surface stay
                  in the masthead. */}
              <div className="bg-squares relative isolate overflow-hidden bg-primary px-6 pt-6 pb-5 text-white">
                <SessionsIcon className="absolute -top-4 -end-4 -z-10 h-28 w-28 text-white/10" />
                <p className="mb-1 flex items-center gap-2 text-sm font-semibold text-white/80">
                  <span className="grid h-8 w-8 place-items-center rounded-lg bg-accent text-accent-foreground">
                    <SessionsIcon className="h-4 w-4" />
                  </span>
                  الحجز مع
                </p>
                <p className="mt-2 text-2xl font-extrabold text-balance">
                  {teacher.name}
                </p>
              </div>

              {/*
                ⚠️ ONE CONTROL: the teacher's free recorded lesson, for every
                reader (owner decision 2026-10-09). It used to send a guest to
                signup and a signed-in reader to their panel — a loop with no
                booking in it. Without a watchable lesson it leads to the
                teacher's courses, where groups and private lessons are booked.
              */}
              <div className="px-6 pt-5 pb-3">
                <TrialCta
                  trial={teacher.trial_lesson}
                  coursesHref={`/teachers/${teacher.slug}?tab=courses`}
                  variant="profile"
                />
              </div>
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
                        className="mb-4 flex items-center gap-3 text-xl font-extrabold text-ink"
                      >
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                          <SessionsIcon className="h-5 w-5" />
                        </span>
                        فيديو تعريفي
                      </h2>
                      {/* نسبةُ ١٦:٩ بالصنفِ القائم، فلا يقفزُ التخطيطُ عندَ التحميل. */}
                      <div className="aspect-video overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-lg shadow-primary/10">
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
                      className="mb-4 flex items-center gap-3 text-xl font-extrabold text-ink"
                    >
                      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                        <UserIcon className="h-5 w-5" />
                      </span>
                      نبذة عن المدرّس
                    </h2>
                    <p className="whitespace-pre-line text-base leading-loose text-ink-muted">
                      {teacher.bio ?? "لم يضف هذا المدرّس نبذة بعد."}
                    </p>
                  </section>

                  {teacher.qualifications.length > 0 && (
                    <section aria-labelledby="quals-heading">
                      <h2
                        id="quals-heading"
                        className="mb-4 flex items-center gap-3 text-xl font-extrabold text-ink"
                      >
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                          <LearningIcon className="h-5 w-5" />
                        </span>
                        المؤهلات والشهادات
                      </h2>
                      <ul className="grid gap-3 sm:grid-cols-2">
                        {teacher.qualifications.map((qualification) => (
                          <li
                            key={qualification}
                            className="flex items-start gap-3 rounded-2xl border border-line bg-surface-raised px-4 py-3.5 text-sm font-medium leading-relaxed text-ink transition duration-200 ease-out hover:border-primary/40 hover:shadow-md hover:shadow-primary/10 motion-reduce:transition-none"
                          >
                            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-white">
                              <CheckIcon className="h-3.5 w-3.5" />
                            </span>
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
                        className="flex items-center gap-3 text-xl font-extrabold text-ink"
                      >
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                          <CoursesIcon className="h-5 w-5" />
                        </span>
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

                {/* The teacher's books and notes, when they sell any — the store's
                    own cards, and a way through to the whole shelf. */}
                {storeItems.length > 0 && (
                  <section aria-labelledby="store-heading" className="space-y-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                      <h2 id="store-heading" className="flex items-center gap-3 text-xl font-extrabold text-ink">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                          <OrdersIcon className="h-5 w-5" />
                        </span>
                        كتب ومذكّرات {teacher.name}
                      </h2>
                      <Link
                        href={`/store?teacher=${encodeURIComponent(teacher.uuid)}`}
                        className="text-sm font-semibold text-primary-ink hover:underline"
                      >
                        كل منتجات المدرّس
                      </Link>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                      {storeItems.slice(0, 4).map((item) => (
                        <PublicStoreCard key={item.uuid} item={item} />
                      ))}
                    </div>
                  </section>
                )}
              </div>
            )}
            {active === "faq" && (
              <div className="space-y-4">
                <div>
                  <h2 className="flex items-center gap-3 text-xl font-extrabold text-ink">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                      <QuestionIcon className="h-5 w-5" />
                    </span>
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
      {/* `StickyCtaBar` publishes its height so the floating WhatsApp button
          stands above it; the bottom padding clears the phone's home indicator. */}
      <StickyCtaBar className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
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
          <TrialCta
            trial={teacher.trial_lesson}
            coursesHref={`/teachers/${teacher.slug}?tab=courses`}
            variant="bar"
          />
        </div>
      </StickyCtaBar>
    </div>
  );
}
