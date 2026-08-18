"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { formatMinorMoney, lessonTypeLabel } from "@/lib/labels";
import type { Course } from "@/lib/types";
import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { EmptyState } from "@/components/ui/states/EmptyState";

interface CourseDetail extends Course {
  sections?: Array<{
    id: number;
    title: string;
    order: number;
    is_published: boolean;
    chapters?: Array<{
      id: number;
      title: string;
      order: number;
      lessons?: Array<{
        uuid: string;
        title: string;
        type: string;
        order: number;
        is_preview: boolean;
        duration_seconds: number;
      }>;
    }>;
  }>;
}

export default function CourseDetailPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);

  const [course, setCourse] = useState<CourseDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<CourseDetail>(`/courses/${uuid}`)
      .then(setCourse)
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton count={4} />;
  if (failed || !course) return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      {/* A soft tint, not a saturated fill. Saturated blocks are reserved for
          status in this product — the pass/fail banner and the verified
          certificate — and a decorative one competes with them. */}
      <div className="rounded-2xl bg-primary-soft p-8">
        <h2 className="mb-2 text-3xl font-bold text-ink">{course.title}</h2>
        <p className="mb-4 max-w-2xl text-ink-muted">{course.description}</p>
        <div className="flex flex-wrap items-center gap-3">
          {course.is_free ? (
            <Badge tone="success">مجاني</Badge>
          ) : (
            <Badge tone="warning">
              <bdi>{formatMinorMoney(course.price_minor, course.currency)}</bdi>
            </Badge>
          )}
          <span className="text-sm text-ink-muted">
            {course.is_sequential ? "تسلسل إجباري للدروس" : "ترتيب مرن للدروس"}
          </span>
        </div>
      </div>

      {/*
        No enrol and no purchase button. This page lives under /manage — it is
        the teacher's view of a course they own, and offering them their own
        course for sale was a student screen copied into an author's one. The
        student path is /enrollments and /learn.
      */}
      <div className="flex flex-wrap gap-3">
        {/* First, and primary: content is what a course is. The other two edit
            its wrapper. */}
        <Button href={`/manage/courses/${uuid}/content`}>محتوى الكورس</Button>

        <Button href={`/manage/courses/${uuid}/edit`} variant="secondary">
          تعديل الكورس
        </Button>

        <Button href="/exams" variant="secondary">
          اختبارات الكورس
        </Button>
      </div>

      {/*
        An empty course used to render nothing at all below the header, which
        reads as a page that failed rather than a course with no content yet.

        The copy has been wrong twice. It first sent the teacher to /admin,
        where CourseResource has no RelationManagers and no section could be
        created either; then it admitted the surface did not exist. Now it
        does, so the empty state does what an empty state should: name the
        absence and point at the one control that fixes it.
      */}
      {(!course.sections || course.sections.length === 0) && (
        <EmptyState
          title="لا محتوى منشور في هذا الكورس بعد"
          description="هذه الصفحة تعرض ما يراه طلابك. مسودّاتك — إن وُجدت — تظهر في صفحة المحتوى، ومن هناك تنشرها. وتسجيلات الحصص المباشرة تظهر تلقائياً بعد نشرها."
          action={<Button href={`/manage/courses/${uuid}/content`}>افتح محتوى الكورس</Button>}
        />
      )}

      {course.sections && course.sections.length > 0 && (
        <Card as="section">
          <h3 className="mb-4 font-semibold text-ink">محتوى الكورس</h3>
          <div className="space-y-4">
            {course.sections.map((section) => (
              <div key={section.id}>
                <h4 className="mb-2 text-sm font-medium text-ink">{section.title}</h4>

                {section.chapters?.map((chapter) => (
                  // ms-*, not ml-*: the indent has to grow from the right in RTL.
                  <div key={chapter.id} className="ms-4 space-y-1">
                    <p className="text-xs text-ink-muted">{chapter.title}</p>

                    <ul className="ms-4 space-y-1">
                      {chapter.lessons?.map((lesson) => (
                        <li key={lesson.uuid} className="flex items-center gap-2 text-sm">
                          <span aria-hidden="true" className="text-ink-muted">
                            ·
                          </span>
                          <span
                            className={
                              lesson.is_preview ? "text-primary-ink" : "text-ink-muted"
                            }
                          >
                            {lesson.title}
                          </span>
                          <span className="text-xs text-ink-muted">
                            ({lessonTypeLabel(lesson.type)})
                          </span>
                          {lesson.is_preview && <Badge tone="info">معاينة مجانية</Badge>}
                          {/*
                            ⚠️ IT SAID «الفيديو» AND POINTED AT A VIDEO-ONLY PAGE
                            — FOR EVERY ITEM, WHATEVER ITS TYPE. Opening an
                            article from this list therefore offered to upload a
                            video for it, and the server refused with «هذا النوع
                            من العناصر لا يحمل ملفاً خاصاً به»: a correct answer
                            to a request the screen invented.

                            The authoring surface renders by the item's own type,
                            so it is the only correct destination for a link that
                            does not know which type it is looking at.
                          */}
                          <Link
                            href={`/manage/courses/${course.uuid}/content?lesson=${lesson.uuid}`}
                            className="text-xs text-primary-ink underline"
                          >
                            تحرير
                          </Link>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
