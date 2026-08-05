"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMoney, lessonTypeLabel } from "@/lib/labels";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

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
  const router = useRouter();

  const [course, setCourse] = useState<CourseDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [enrolling, setEnrolling] = useState(false);
  const [ordering, setOrdering] = useState(false);
  const [error, setError] = useState("");

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

  const enroll = async () => {
    setEnrolling(true);
    setError("");
    try {
      await api.post(`/courses/${uuid}/enroll`);
      router.push("/enrollments");
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setEnrolling(false);
    }
  };

  // Manual bank transfer: the order is created as pending and a teacher approves
  // it from /orders, which is what creates the enrollment.
  const purchase = async () => {
    setOrdering(true);
    setError("");
    try {
      await api.post(`/courses/${uuid}/orders`);
      router.push("/orders");
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setOrdering(false);
    }
  };

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
              <bdi>{formatMoney(course.price, course.currency)}</bdi>
            </Badge>
          )}
          <span className="text-sm text-ink-muted">
            {course.is_sequential ? "تسلسل إجباري للدروس" : "ترتيب مرن للدروس"}
          </span>
        </div>
      </div>

      {error && <Alert tone="danger" title={error} />}

      <div className="flex flex-wrap gap-3">
        <Button href={`/manage/courses/${uuid}/edit`} variant="secondary">
          تعديل الكورس
        </Button>

        {course.is_free ? (
          <Button loading={enrolling} loadingLabel="جارٍ التسجيل…" onClick={enroll}>
            سجّل مجاناً
          </Button>
        ) : (
          <Button loading={ordering} loadingLabel="جارٍ إنشاء الطلب…" onClick={purchase}>
            اشترِ بـ <bdi>{formatMoney(course.price, course.currency)}</bdi>
          </Button>
        )}
      </div>

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
