import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { publicApi, type HomePayload } from "@/lib/public-api";
import { platformName } from "@/lib/platform";
import { siteUrl } from "@/lib/site";
import { TeacherCard } from "@/components/marketplace/TeacherCard";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { TestimonialsCarousel } from "@/components/marketplace/TestimonialsCarousel";
import { SubjectsGrid } from "@/components/marketplace/SubjectsGrid";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { AnimatedNumber } from "@/components/ui/AnimatedNumber";
import {
  AcademicCapIcon,
  SessionsIcon,
  StarIcon,
  UsersIcon,
} from "@/components/icons";

/*
 * ⚠️ THE HOME PAGE HAD NO `metadata` AT ALL until spec 011 — the ONE public page
 * in the product without one, inheriting the root layout's default title and
 * description. It is also the first thing a search engine reads about the
 * platform, and an OG card with no image and no explicit description is what a
 * link to the front page renders as in every chat app it is pasted into.
 *
 * `alternates.canonical` is absolute for the same reason the sitemap's entries
 * are: the marketplace answers at more than one host in development, and a
 * relative canonical resolves against whichever one the crawler arrived on.
 */
export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    // `default`, not a string: the root layout's template appends «| المنصّة», and
    // on the home page that would read as the platform name twice.
    title: { absolute: `${name} — مدرّسون خصوصيون بالعربية، مباشرةً ومسجّلاً` },
    description:
      "ابحثْ عن مدرّسٍ خصوصيٍّ لكلِّ مادّةٍ ومرحلة: حصصٌ فرديّةٌ وجماعيّةٌ مباشرة، وكورساتٌ مسجّلة، ودرجةُ ثقةٍ شفّافةٌ لكلِّ مدرّس.",
    alternates: { canonical: siteUrl("/") },
    openGraph: {
      title: `${name} — مدرّسون خصوصيون بالعربية`,
      description:
        "حصصٌ خصوصيّةٌ مباشرةٌ ومسجّلة، مع مراجعةٍ أكاديميّةٍ لكلِّ مدرّسٍ ودرجةِ ثقةٍ مبنيّةٍ على أداءٍ فعليّ.",
      url: siteUrl("/"),
      siteName: name,
      type: "website",
      locale: "ar_QA",
    },
    twitter: { card: "summary_large_image" },
  };
}

// Server-rendered: a crawler that runs no JavaScript must still read the teachers
// and copy (SC-016), and client-side fetching would put first paint out of reach
// of SC-007.
export const revalidate = 60;

const STEPS = [
  {
    title: "اختر المادة",
    body: "تصفّح المواد والمراحل الدراسية وحدّد ما يحتاجه ابنك أو تحتاجه أنت.",
  },
  {
    title: "اختر المدرّس",
    body: "قارن بين المدرّسين بالتقييمات ودرجة الثقة والسعر وأوقات التوفّر.",
  },
  {
    title: "احجز الحصة",
    body: "اختر الوقت المناسب من جدول المدرّس واحجز حصة تجريبية أو باقة كاملة.",
  },
  {
    title: "ابدأ التعلّم",
    body: "احضر الحصة مباشرة أو شاهد المسجّلة في وقتك، وتابع تقدّمك أولاً بأول.",
  },
];

/**
 * The platform's headline numbers.
 *
 * ⚠️ EVERY ONE OF THEM IS A SUM OVER REAL ROWS, not a marketing figure.
 * `GetMarketplaceStats` aggregates `teacher_profiles` narrowed by
 * `publiclyListed()` — so a teacher who has not been approved, or whose workspace
 * withdrew, is absent from the count as well as from the list. Verified against
 * the database on 2026-08-24: the endpoint and a hand-written aggregate returned
 * the same four values.
 *
 * ⚠️ AND THE ICON IS `aria-hidden`, WITH THE LABEL CARRYING THE MEANING. It is
 * decoration beside a number that already says what it is; announced, a screen
 * reader would read «مجموعة أشخاص، ٤٤٢، طالب».
 */
function StatBar({ stats }: { stats: HomePayload["stats"] }) {
  const items = [
    { label: "طالب", value: stats.students, Icon: UsersIcon },
    { label: "مدرّس", value: stats.teachers, Icon: AcademicCapIcon },
    { label: "حصة مكتملة", value: stats.sessions, Icon: SessionsIcon },
    { label: "معدّل الرضا", value: stats.satisfaction_rate, suffix: "٪", Icon: StarIcon },
  ];

  return (
    <section aria-label="أرقام المنصة" className="border-y border-line bg-surface-raised">
      {/*
        | ⚠️ `auto-fit` WITH A FLOOR, NOT A COLUMN COUNT PER BREAKPOINT.
        |
        | This was `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`, which stacks all
        | four numbers on top of each other below 640px — the width most of Qatar
        | reads this page at. Four full-width rows push the whole «كيف تعمل
        | المنصة» section below the fold on a phone, to say four things that are
        | each a word and a number.
        |
        | A breakpoint answers «how wide is the SCREEN»; the question here is «how
        | wide is a TILE», and the two stop agreeing the moment a label or a digit
        | is added. The floor states it once — measured, not guessed: two across
        | on a 320px phone, three at 640, four from ~790 up, and never more than
        | four because there are four items and `auto-fit` collapses the rest.
        |
        | ⚠️ THERE ARE TWO FLOORS BECAUSE THE ICON CHANGES SIZE AT `sm`. A single
        | 7.5rem floor gave four 126px tracks at 640 against a 3rem disc and a
        | 1.5rem gap — «حصة مكتملة» wrapped to two lines and the row overflowed
        | its own tile. A floor is a statement about the CONTENT, so it has to
        | move with the content it holds.
      */}
      <dl className="mx-auto grid max-w-7xl grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))] gap-x-4 gap-y-6 px-4 py-10 sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] sm:gap-6 sm:px-6">
        {items.map(({ label, value, suffix, Icon }) => (
          // Icon on one side, the number and its label on the other. `text-start`
          // and not `text-left`: the row mirrors with the page and needs no
          // second rule to do it.
          <div key={label} className="flex items-center gap-3 text-start sm:gap-4">
            <span
              aria-hidden="true"
              // Smaller on a phone: at the 7.5rem floor a 3rem disc plus its
              // gap leaves «حصة مكتملة» too little to sit on one line.
              className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink sm:h-12 sm:w-12"
            >
              <Icon className="h-5 w-5 sm:h-6 sm:w-6" />
            </span>

            <div className="min-w-0">
              <dd className="text-2xl font-extrabold leading-tight text-primary-ink sm:text-3xl lg:text-4xl">
                <AnimatedNumber value={value} suffix={suffix} />
              </dd>
              <dt className="text-sm text-ink-muted">{label}</dt>
            </div>
          </div>
        ))}
      </dl>
    </section>
  );
}

export default async function HomePage() {
  const name = await platformName();

  let home: HomePayload;

  try {
    home = await publicApi.home();
  } catch {
    return (
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

  return (
    <>
      {/* bg-grid paints squared-paper lines behind the hero and fades them out
          before they reach the body copy. Decorative only — it is a ::before with
          no content, so nothing new lands in the accessibility tree. */}
      <section className="bg-grid mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
        <div>
          <h1 className="mb-5 text-3xl font-extrabold leading-tight text-ink sm:text-4xl lg:text-5xl">
            مدرّسك الخصوصي الموثوق،
            <span className="text-primary-ink"> أينما كنت في العالم العربي</span>
          </h1>
          <p className="mb-8 max-w-xl text-lg leading-relaxed text-ink-muted">
            حصص فردية وجماعية، مباشرة ومسجّلة، مع مدرّسين يمرّون بمراجعة أكاديمية
            قبل انضمامهم — وتقييم شفاف يوضّح التزام كل مدرّس قبل أن تحجز.
          </p>
          {/* Centred: the two doors are a CHOICE between equals, and a choice
              reads as one when neither is first. `justify-center` and not
              `mx-auto` — the row is a flex container, so centring belongs to
              how it distributes its children, not to a width it does not set. */}
          <div className="flex flex-wrap justify-center gap-3">
            <Link
              href="/signup/student"
              className="rounded-xl bg-primary px-6 py-3 text-base font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              سجّل كطالب
            </Link>
            <Link
              href="/signup/teacher"
              className="rounded-xl border border-primary px-6 py-3 text-base font-semibold text-primary-ink transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              سجّل كمدرّس
            </Link>
          </div>
        </div>

        {/*
          | The photo, not the placeholder icon that shipped here before.
          |
          | Self-hosted under /marketplace and logged in that folder's
          | LICENSES.md — a hotlinked stock URL is a page that breaks the day
          | someone else's account lapses.
          |
          | `priority`, because this is the LCP element on the home page: Next
          | lazy-loads images by default, and the largest thing above the fold
          | loading last is the whole of SC-007 lost to a default.
          |
          | The 4/3 aspect is baked into the file itself (the crop is 1600×1200),
          | so the box cannot letterbox or jump while it loads.
        */}
        <div className="relative aspect-[4/3] overflow-hidden rounded-3xl bg-surface-raised">
          <Image
            src="/marketplace/hero-study.webp"
            alt="طالبة تراجع دروسها"
            fill
            priority
            sizes="(min-width: 1024px) 50vw, 100vw"
            className="object-cover"
          />
        </div>
      </section>

      <StatBar stats={home.stats} />

      {/*
        | A path, not four cards.
        |
        | These are four MOMENTS OF ONE JOURNEY, and four identically sized
        | bordered boxes say the opposite — they say "four features", which is
        | the shape every generated landing page reaches for and the reason this
        | section read as machine-made. The numbers stay, because here the order
        | is the information: you cannot attend before you book.
        |
        | The rule is drawn once behind the row and the markers sit ON it, so the
        | connection is a line the eye follows rather than a gap it infers. It is
        | hidden below `lg`, where the steps stack and a horizontal rule would
        | run through nothing.
      */}
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <h2 className="mb-12 text-center text-2xl font-extrabold text-ink sm:text-3xl">
          كيف تعمل المنصة
        </h2>

        <div className="relative">
          {/* Spans marker centre to marker centre, not edge to edge. With four
              equal columns those centres sit at 12.5% and 87.5%, so a rule that
              runs the full width sticks out past the first and last steps and
              reads as a track the journey is only part of. */}
          <div
            className="absolute inset-x-[12.5%] top-5 hidden h-px bg-line lg:block"
            aria-hidden="true"
          />

          {/* One column until `lg`, not two at `sm`. Two columns cannot carry a
              connector — the second column's line would join steps that do not
              follow each other — and a journey that loses its thread on a tablet
              is four boxes again. */}
          <ol className="relative grid gap-10 lg:grid-cols-4 lg:gap-8">
            {STEPS.map((step, index) => (
              <li
                key={step.title}
                className="group relative flex gap-4 text-start lg:block lg:text-center lg:px-3"
              >
                {/* The vertical half of the same rule, for the stacked layout.
                    Qatar reads this on a phone first, and a connector that only
                    exists on desktop leaves the journey as four unrelated blocks
                    exactly where most people meet it.

                    `start-5` is the marker's centre (w-10 ÷ 2), and `-bottom-10`
                    is the `gap-10` between items, so the line lands ON the next
                    marker instead of stopping short of it. Not drawn after the
                    last step, and gone at `lg` where the horizontal rule takes
                    over. */}
                {index < STEPS.length - 1 && (
                  <span
                    className="absolute start-5 top-12 -bottom-10 w-px bg-line lg:hidden"
                    aria-hidden="true"
                  />
                )}

                <span
                  // bg-surface, not transparent: the marker has to punch a hole
                  // in the rule it sits on, or the line runs through the digit.
                  className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-base font-bold text-primary-ink transition duration-300 ease-out group-hover:border-primary group-hover:bg-primary group-hover:text-white lg:mx-auto lg:mb-5"
                >
                  {(index + 1).toLocaleString("ar-QA")}
                </span>

                <span className="lg:block">
                  <span className="mb-2 block text-base font-bold text-ink">
                    {step.title}
                  </span>
                  <span className="mx-auto block max-w-xs text-sm leading-relaxed text-ink-muted">
                    {step.body}
                  </span>
                </span>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <div className="mb-8 flex items-end justify-between gap-4">
          <h2 className="text-2xl font-extrabold text-ink sm:text-3xl">أفضل المدرّسين</h2>
          <Link href="/teachers" className="text-sm font-semibold text-primary-ink hover:underline">
            عرض الكل
          </Link>
        </div>

        {home.featured_teachers.length === 0 ? (
          <EmptyState
            title="لم ينضم مدرّسون بعد"
            description="نراجع حالياً طلبات أول دفعة من المدرّسين. إن كنت مدرّساً، يمكنك التقديم الآن."
            action={
              <Link
                href="/signup/teacher"
                className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
              >
                سجّل كمدرّس
              </Link>
            }
          />
        ) : (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {home.featured_teachers.map((teacher) => (
              <TeacherCard key={teacher.uuid} teacher={teacher} />
            ))}
          </div>
        )}
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <div className="mb-8 flex items-end justify-between gap-4">
          <h2 className="text-2xl font-extrabold text-ink sm:text-3xl">
            الكورسات المميزة
          </h2>
          <Link href="/courses" className="text-sm font-semibold text-primary-ink hover:underline">
            عرض الكل
          </Link>
        </div>

        {home.featured_courses.length === 0 ? (
          <EmptyState
            title="لا توجد كورسات منشورة بعد"
            description="ابدأ بتصفّح المدرّسين واحجز حصة تجريبية مباشرة معهم."
            action={
              <Link
                href="/teachers"
                className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
              >
                تصفّح المدرّسين
              </Link>
            }
          />
        ) : (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {home.featured_courses.map((course) => (
              <CourseCard key={course.uuid} course={course} />
            ))}
          </div>
        )}
      </section>

      {/* Absent until a student writes one — the carousel returns null on an
          empty list, so a launch-day page simply does not carry this section
          rather than carrying invented quotes. */}
      {home.testimonials.length > 0 && (
        <section className="bg-surface-raised py-16">
          <div className="mx-auto max-w-7xl px-4 sm:px-6">
            <h2 className="mb-10 text-center text-2xl font-extrabold text-ink sm:text-3xl">
              ماذا كتب الطلاب عن مدرّسيهم
            </h2>
            <TestimonialsCarousel items={home.testimonials} />
          </div>
        </section>
      )}

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <h2 className="mb-10 text-center text-2xl font-extrabold text-ink sm:text-3xl">
          المواد الدراسية
        </h2>
        <SubjectsGrid subjects={home.subjects} />
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <FaqAccordion items={home.faqs} heading="الأسئلة الشائعة" />
      </section>

      <section className="mx-auto max-w-7xl px-4 pb-20 sm:px-6">
        <div className="rounded-3xl bg-primary px-6 py-12 text-center">
          <h2 className="mb-3 text-2xl font-extrabold text-white sm:text-3xl">
            ابدأ رحلتك مع {name} اليوم
          </h2>
          <p className="mx-auto mb-7 max-w-xl text-white/85">
            أنشئ حسابك مجاناً، وتصفّح المدرّسين، واحجز حصتك التجريبية الأولى.
          </p>
          <Link
            href="/signup/student"
            className="inline-block rounded-xl bg-accent px-6 py-3 text-base font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            سجّل كطالب مجاناً
          </Link>
        </div>
      </section>
    </>
  );
}
