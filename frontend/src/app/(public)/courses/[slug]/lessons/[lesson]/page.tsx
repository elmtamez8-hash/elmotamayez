import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { EmbeddedVideo } from "@/components/player/EmbeddedVideo";
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
    <div className="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-10 sm:px-6">
      <nav className="text-sm">
        <Link href={`/courses/${preview.course.slug}`} className="text-primary-ink underline">
          {preview.course.title}
        </Link>
      </nav>

      <header className="flex flex-col gap-2">
        <span className="w-fit rounded-lg bg-secondary/15 px-2 py-0.5 text-xs font-medium text-secondary-ink">
          حصّة تعريفيّة مجّانيّة
        </span>
        <h1 className="text-2xl font-extrabold text-ink">{preview.title}</h1>
        {/*
          ⚠️ ABSENT, NOT «٠ دقيقة» (FR-018). The column defaults to zero and the
          teacher writes it by hand, so the server omits the key rather than
          sending a number that is a lie about a video nobody measured.
        */}
        {length !== null && <p className="text-sm text-ink-muted">{length}</p>}
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
      <aside className="flex flex-col gap-3 rounded-2xl border border-line bg-surface-raised p-5">
        <h2 className="font-bold text-ink">أعجبتك الحصّة؟</h2>
        <p className="text-sm text-ink-muted">
          بقيّة دروس «{preview.course.title}» تُفتح بالتسجيل في الكورس.
        </p>
        <Link
          href={`/courses/${preview.course.slug}`}
          className="w-fit rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white"
        >
          سجّل في الكورس
        </Link>
      </aside>
    </div>
  );
}
