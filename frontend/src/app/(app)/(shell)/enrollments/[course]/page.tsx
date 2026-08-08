"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { api } from "@/lib/api";
import type { Course } from "@/lib/types";
import { Card } from "@/components/ui/Card";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

/**
 * The lessons of a course the viewer is enrolled in.
 *
 * The segment is the *course* uuid, not the enrollment's: everything shown here
 * comes from `GET /courses/{uuid}`, which already nests sections → chapters →
 * lessons, and a second lookup to turn one uuid into the other would buy
 * nothing.
 *
 * This screen exists because `/learn/{lesson}` had nothing pointing at it — the
 * player shipped with no route into it from anywhere in the product.
 */

interface Lesson {
  uuid: string;
  title: string;
  type: string;
  order: number;
  duration_seconds: number;
}

/**
 * Mirrors `CourseSectionResource`, which 016 rewrote.
 *
 * This shape said `id` and `is_published`, and the API stopped sending either:
 * nodes are addressed by uuid now, and `is_published` became `status`, because a
 * node also has to be able to be ARCHIVED — a lesson any student has progress on
 * may never be hard-deleted, so "gone from the course" has to be a state the row
 * can hold. The filter below then matched `undefined` on every section and this
 * page told every enrolled student their course had no lessons in it.
 */
interface CourseWithLessons extends Course {
  sections?: Array<{
    uuid: string;
    title: string;
    status: string;
    chapters?: Array<{ uuid: string; title: string; status: string; lessons?: Lesson[] }>;
  }>;
}

function minutes(seconds: number): string {
  return seconds > 0 ? `${Math.max(1, Math.round(seconds / 60))} دقيقة` : "";
}

export default function CourseLessonsPage({
  params,
}: {
  params: Promise<{ course: string }>;
}) {
  const { course: courseUuid } = use(params);

  const [course, setCourse] = useState<CourseWithLessons | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<CourseWithLessons>(`/courses/${courseUuid}`)
      .then(setCourse)
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [courseUuid]);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton />;
  if (failed || course === null) return <ErrorState onRetry={load} />;

  // Published, not "not draft": an archived section is one the teacher has
  // retired, and showing it would put back exactly what archiving removed.
  const sections = (course.sections ?? []).filter((s) => s.status === "published");
  const lessonCount = sections.reduce(
    (total, s) =>
      total +
    (s.chapters ?? [])
      .filter((c) => c.status === "published")
      .reduce((n, c) => n + (c.lessons?.length ?? 0), 0),
    0,
  );

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/enrollments"
          className="rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          ← تعلّمي
        </Link>
        <h2 className="mt-1 text-2xl font-bold text-ink">{course.title}</h2>
      </div>

      {lessonCount === 0 ? (
        <EmptyState
          title="لا دروس منشورة بعد"
          description="سيظهر محتوى الكورس هنا فور نشر المدرّس أول درس."
        />
      ) : (
        sections.map((section) => (
          <Card key={section.uuid}>
            <h3 className="mb-3 font-semibold text-ink">{section.title}</h3>

            {(section.chapters ?? [])
              .filter((chapter) => chapter.status === "published")
              .map((chapter) => (
              <div key={chapter.uuid} className="mb-4 last:mb-0">
                <p className="mb-2 text-sm text-ink-muted">{chapter.title}</p>

                <ul className="space-y-2">
                  {(chapter.lessons ?? []).map((lesson) => (
                    <li key={lesson.uuid}>
                      {/*
                        Every type links now. It used to be video alone, and the
                        comment said a dead link was a worse promise than a plain
                        row — which was true while `/learn/{lesson}` played video
                        and nothing else. It no longer does, so the row that was
                        a promise not to disappoint is now a lesson nobody can
                        open.
                      */}
                      <Link
                        href={`/learn/${lesson.uuid}`}
                        className="flex items-center justify-between gap-3 rounded-lg border border-line p-3 text-sm text-ink transition hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                      >
                        <span className="truncate">{lesson.title}</span>
                        <span className="shrink-0 text-xs text-ink-muted">
                          <bdi>{minutes(lesson.duration_seconds)}</bdi>
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </Card>
        ))
      )}
    </div>
  );
}
