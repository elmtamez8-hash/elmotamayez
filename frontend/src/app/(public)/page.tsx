import Image from "next/image";
import Link from "next/link";
import { publicApi, type HomePayload } from "@/lib/public-api";
import { PLATFORM_NAME } from "@/lib/platform";
import { TeacherCard } from "@/components/marketplace/TeacherCard";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { TestimonialsCarousel } from "@/components/marketplace/TestimonialsCarousel";
import { SubjectsGrid } from "@/components/marketplace/SubjectsGrid";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

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

function StatBar({ stats }: { stats: HomePayload["stats"] }) {
  const items = [
    { label: "طالب", value: stats.students },
    { label: "مدرّس", value: stats.teachers },
    { label: "حصة مكتملة", value: stats.sessions },
    { label: "معدّل الرضا", value: stats.satisfaction_rate, suffix: "٪" },
  ];

  return (
    <section aria-label="أرقام المنصة" className="border-y border-line bg-surface-raised">
      <dl className="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-4 py-10 sm:px-6 lg:grid-cols-4">
        {items.map((item) => (
          <div key={item.label} className="text-center">
            <dt className="order-2 text-sm text-ink-muted">{item.label}</dt>
            <dd className="order-1 text-3xl font-extrabold text-primary-ink">
              {item.value.toLocaleString("ar-QA")}
              {item.suffix}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export default async function HomePage() {
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
          <div className="flex flex-wrap gap-3">
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

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        <h2 className="mb-10 text-center text-2xl font-extrabold text-ink sm:text-3xl">
          كيف تعمل المنصة
        </h2>
        <ol className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {STEPS.map((step, index) => (
            <li
              key={step.title}
              className="rounded-2xl border border-line bg-surface-raised p-6"
            >
              <span className="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-primary text-base font-bold text-white">
                {index + 1}
              </span>
              <h3 className="mb-2 text-base font-bold text-ink">{step.title}</h3>
              <p className="text-sm leading-relaxed text-ink-muted">{step.body}</p>
            </li>
          ))}
        </ol>
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
            ابدأ رحلتك مع {PLATFORM_NAME} اليوم
          </h2>
          <p className="mx-auto mb-7 max-w-xl text-primary-soft">
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
