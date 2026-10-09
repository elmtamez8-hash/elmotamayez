import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { EmbeddedVideo } from "@/components/player/EmbeddedVideo";
import {
  AcademicCapIcon,
  ChevronEndIcon,
  ChevronStartIcon,
  ClockIcon,
  SparkIcon,
} from "@/components/icons";
import { NotFoundError, publicApi, type PreviewLesson } from "@/lib/public-api";
import { counted } from "@/lib/labels";
import { siteUrl } from "@/lib/site";

type Params = { slug: string; lesson: string };

/*
 * ⚠️ `[slug]` AND NOT `[uuid]`, AND THIS IS NOT A NAMING PREFERENCE.
 *
 * Next refuses two different dynamic segment names at one path position, and it
 * refuses them by failing the build of THE WHOLE APPLICATION rather than this
 * page — the `/privacy` family, where two route files resolving to one path took
 * `/`, `/login` and `/dashboard` down together and sat for a session because
 * neither `tsc` nor `npm test` builds routes.
 */

async function loadLesson(courseKey: string, lessonUuid: string): Promise<PreviewLesson> {
  try {
    const { data } = await publicApi.previewLesson(courseKey, lessonUuid);

    return data;
  } catch (error) {
    /*
     * The API answers 404 identically for seven different reasons — a locked
     * lesson, a draft section, an unlisted teacher, a uuid nobody issued. That
     * uniformity IS the guard (FR-009), so a distinct page for any of them would
     * confirm the very thing the identical refusal exists to withhold.
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
  const { slug, lesson } = await params;

  try {
    const preview = await loadLesson(slug, lesson);

    return {
      title: `${preview.title} — ${preview.course.title}`,
      description: `حصّة تعريفيّة مجّانيّة من «${preview.course.title}» — تُشاهَد بلا تسجيل.`,
      alternates: {
        canonical: siteUrl(`/courses/${preview.course.slug}/lessons/${preview.uuid}`),
      },
    };
  } catch {
    return { title: "غير متاح" };
  }
}

function duration(seconds: number | undefined): string | null {
  if (seconds === undefined || seconds <= 0) return null;

  const minutes = Math.round(seconds / 60);

  return counted(minutes, {
    one: "دقيقة",
    two: "دقيقتان",
    few: "دقائق",
    many: "دقيقة",
    other: "دقيقة",
  });
}

export default async function PreviewLessonPage({
  params,
}: {
  params: Promise<Params>;
}) {
  const { slug, lesson } = await params;
  const preview = await loadLesson(slug, lesson);
  const length = duration(preview.duration_seconds);

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-8 px-4 py-10 sm:px-6">
      <nav className="text-sm">
        <Link
          href={`/courses/${preview.course.slug}`}
          className="group inline-flex max-w-full items-center gap-2 rounded-full border border-line bg-surface-raised px-4 py-2 font-bold text-primary-ink shadow-sm transition hover:border-primary/40 hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
        >
          <span
            aria-hidden="true"
            className="transition-transform duration-300 ease-out group-hover:translate-x-1 motion-reduce:transition-none"
          >
            <ChevronStartIcon />
          </span>
          <span className="truncate">{preview.course.title}</span>
        </Link>
      </nav>

      {/*
        ⚠️ العنوانُ أوّلاً، والشارةُ تحتَه في سطرِ الحقائق — لا لافتةَ فوقَ عنوان.
      */}
      <header className="flex flex-col gap-4">
        <h1 className="text-balance text-3xl font-extrabold leading-tight text-ink sm:text-4xl">
          {preview.title}
        </h1>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary/15 px-3.5 py-1.5 font-extrabold text-secondary-ink">
            <span aria-hidden="true">
              <SparkIcon />
            </span>
            حصّة تعريفيّة مجّانيّة
          </span>
          {/*
            ⚠️ ABSENT, NOT «٠ دقيقة» (FR-018). The column defaults to zero and the
            teacher writes it by hand, so the server omits the key rather than
            sending a number that is a lie about a video nobody measured.
          */}
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

      <EmbeddedVideo
        embedUrl={preview.embed_url}
        title={preview.title}
        report={{ courseKey: preview.course.slug, lessonUuid: preview.uuid }}
      />

      {/*
        ⛔ DRAWN FROM THE FIRST PAINT, NEVER AFTER A PLAYBACK EVENT (FR-013).
        Waiting for the video to end would need the host's player library on our
        page — which FR-016 forbids outright — and it would also be wrong: a
        visitor convinced in the third minute never reaches an invitation placed
        at the twentieth.
      */}
      <aside className="bg-squares relative isolate flex flex-col items-start gap-4 overflow-hidden rounded-3xl bg-primary p-6 text-white shadow-xl shadow-primary/20 sm:p-10">
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-8 -end-6 -z-10 text-white/10"
        >
          <AcademicCapIcon className="h-40 w-40 sm:h-52 sm:w-52" />
        </span>
        <h2 className="text-balance text-2xl font-extrabold text-white sm:text-3xl">أعجبتك الحصّة؟</h2>
        <p className="max-w-xl leading-relaxed text-white/80">
          بقيّة دروس «{preview.course.title}» تُفتح بالتسجيل في الكورس.
        </p>
        <Link
          href={`/courses/${preview.course.slug}`}
          className="group mt-2 inline-flex items-center gap-2 rounded-2xl bg-surface-raised px-6 py-3.5 text-sm font-extrabold text-primary-ink shadow-lg shadow-primary-ink/30 transition duration-300 ease-out hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        >
          سجّل في الكورس
          <ChevronEndIcon className="h-4 w-4 transition-transform duration-300 ease-out group-hover:-translate-x-1 motion-reduce:transition-none" />
        </Link>
      </aside>
    </div>
  );
}
