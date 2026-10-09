import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { AcademicCapIcon, ChevronEndIcon, ChevronStartIcon, ClockIcon, PlayIcon } from "@/components/icons";
import { TrialPlayer } from "@/components/marketplace/TrialPlayer";
import { counted } from "@/lib/labels";
import { NotFoundError, publicApi, type CourseDetail } from "@/lib/public-api";
import { siteUrl } from "@/lib/site";

type Params = { slug: string };

/*
| Spec 040 — a course's «حصة تجريبية»: the one recorded lesson its teacher chose
| for anybody to watch, embedded or uploaded.
|
| The frame (title, course, the enrol invitation) is drawn on the server from the
| course payload, whose `trial` is computed by the same rule the guest door
| applies — so this page exists exactly when the door will serve. The video
| itself is fetched by `TrialPlayer` in the browser (per-visitor rate limit).
|
| Inbound links: the course page's button, the course card's badge and the
| teacher page's trial list.
*/

async function loadCourse(slug: string): Promise<CourseDetail> {
  try {
    const { data } = await publicApi.course(slug);

    return data;
  } catch (error) {
    // The same refusal for every reason, as the course page does.
    if (error instanceof NotFoundError) notFound();
    throw error;
  }
}

function duration(seconds: number | undefined): string | null {
  if (seconds === undefined || seconds <= 0) return null;

  return counted(Math.round(seconds / 60), {
    one: "دقيقة",
    two: "دقيقتان",
    few: "دقائق",
    many: "دقيقة",
    other: "دقيقة",
  });
}

export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { slug } = await params;

  try {
    const course = await loadCourse(slug);

    if (course.trial == null) return { title: "غير متاح" };

    return {
      title: `حصة تجريبية — ${course.title}`,
      description: `«${course.trial.title}» من «${course.title}»: حصة مسجّلة تُشاهَد مجاناً بلا تسجيل.`,
      alternates: { canonical: siteUrl(`/courses/${course.slug ?? course.uuid}/trial`) },
    };
  } catch {
    return { title: "غير متاح" };
  }
}

export default async function CourseTrialPage({ params }: { params: Promise<Params> }) {
  const { slug } = await params;
  const course = await loadCourse(slug);

  if (course.trial == null) notFound();

  const length = duration(course.trial.duration_seconds);
  // The course's key for every link: the slug where there is one, else the uuid.
  const key = course.slug ?? course.uuid;
  const teacherName = course.teacher?.name ?? null;

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-8 px-4 py-10 sm:px-6">
      <nav className="text-sm">
        <Link
          href={`/courses/${key}`}
          className="group inline-flex max-w-full items-center gap-2 rounded-full border border-line bg-surface-raised px-4 py-2 font-bold text-primary-ink shadow-sm transition hover:border-primary/40 hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
        >
          <span
            aria-hidden="true"
            className="transition-transform duration-300 ease-out group-hover:translate-x-1 motion-reduce:transition-none"
          >
            <ChevronStartIcon />
          </span>
          <span className="truncate">{course.title}</span>
        </Link>
      </nav>

      <header className="flex flex-col gap-4">
        <h1 className="text-balance text-3xl font-extrabold leading-tight text-ink sm:text-4xl">
          {course.trial.title}
        </h1>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary/15 px-3.5 py-1.5 font-extrabold text-secondary-ink">
            <span aria-hidden="true">
              <PlayIcon />
            </span>
            حصة تجريبية مجانية
          </span>
          {teacherName !== null && (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface-raised px-3.5 py-1.5 font-semibold text-ink-muted">
              مع {teacherName}
            </span>
          )}
          {length !== null && (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface-raised px-3.5 py-1.5 font-semibold text-ink-muted">
              <span aria-hidden="true">
                <ClockIcon />
              </span>
              <bdi>{length}</bdi>
            </span>
          )}
        </div>
      </header>

      <TrialPlayer courseKey={key} />

      <aside className="bg-squares relative isolate flex flex-col items-start gap-4 overflow-hidden rounded-3xl bg-primary p-6 text-white shadow-xl shadow-primary/20 sm:p-10">
        <span aria-hidden="true" className="pointer-events-none absolute -bottom-8 -end-6 -z-10 text-white/10">
          <AcademicCapIcon className="h-40 w-40 sm:h-52 sm:w-52" />
        </span>
        <h2 className="text-balance text-2xl font-extrabold text-white sm:text-3xl">أعجبك الشرح؟</h2>
        <p className="max-w-xl leading-relaxed text-white/80">
          بقيّة «{course.title}»{teacherName === null ? "" : ` مع ${teacherName}`} تُفتح بالاشتراك في الكورس.
        </p>
        <Link
          href={`/courses/${key}`}
          className="group mt-2 inline-flex items-center gap-2 rounded-2xl bg-surface-raised px-6 py-3.5 text-sm font-extrabold text-primary-ink shadow-lg shadow-primary-ink/30 transition duration-300 ease-out hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        >
          اشترك في الكورس
          <ChevronEndIcon className="h-4 w-4 transition-transform duration-300 ease-out group-hover:-translate-x-1 motion-reduce:transition-none" />
        </Link>
      </aside>
    </div>
  );
}
