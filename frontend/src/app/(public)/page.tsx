import Link from "next/link";
import { publicApi, type HomePayload } from "@/lib/public-api";
import { PLATFORM_NAME } from "@/lib/platform";
import { TeacherCard } from "@/components/marketplace/TeacherCard";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { SubjectsGrid } from "@/components/marketplace/SubjectsGrid";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { TestimonialsCarousel } from "@/components/marketplace/TestimonialsCarousel";
import { EmptyState } from "@/components/marketplace/states/EmptyState";
import { ErrorState } from "@/components/marketplace/states/ErrorState";

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
    <section aria-label="أرقام المنصة" className="border-y border-line bg-white dark:bg-transparent">
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
      <section className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
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

        <div className="relative aspect-[4/3] overflow-hidden rounded-3xl bg-primary-soft">
          {/* Replaced with a licensed photo in T135; the placeholder keeps the
              layout honest rather than shipping a hotlinked stock image. */}
          <div className="flex h-full items-center justify-center text-primary-ink/40" aria-hidden="true">
            <svg className="h-32 w-32" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
              />
            </svg>
          </div>
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
              className="rounded-2xl border border-line bg-white p-6 dark:bg-transparent"
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

      <section className="bg-white py-16 dark:bg-transparent">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <h2 className="mb-10 text-center text-2xl font-extrabold text-ink sm:text-3xl">
            ماذا يقول الطلاب وأولياء الأمور
          </h2>
          <TestimonialsCarousel items={home.testimonials} />
        </div>
      </section>

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
