"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMoney, statusLabel, statusTone } from "@/lib/labels";
import type { Course } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { TrashIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

export default function ManageCoursesPage() {
  const [courses, setCourses] = useState<Course[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  /** uuid awaiting a second click to confirm deletion. */
  const [confirming, setConfirming] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Course[] }>("/courses")
      .then((res) => setCourses(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const filtered = search
    ? courses.filter((c) => c.title.toLowerCase().includes(search.toLowerCase()))
    : courses;

  const remove = async (uuid: string) => {
    setDeleting(uuid);
    setError("");
    try {
      await api.delete(`/courses/${uuid}`);
      setCourses((prev) => prev.filter((c) => c.uuid !== uuid));
      setConfirming(null);
    } catch (err: unknown) {
      // Swallowing this made a permission failure look like a successful delete
      // that simply had not rendered yet.
      setError(userMessage(err));
    } finally {
      setDeleting(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">إدارة الكورسات</h2>
          <p className="text-ink-muted">أنشئ كورساتك وحرّرها وانشرها.</p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-56">
            <TextField
              id="course_search"
              label="ابحث في كورساتك"
              type="search"
              value={search}
              onChange={setSearch}
              placeholder="اسم الكورس"
            />
          </div>
          <Button href="/manage/courses/new">كورس جديد</Button>
        </div>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : filtered.length === 0 ? (
        // "No results for your filter" is not "you have no courses": the first
        // needs the filter cleared, the second needs a course created. Offering
        // the wrong one leaves the user staring at data they cannot see.
        search ? (
          <EmptyState
            title="لا نتائج تطابق البحث"
            description={`لا كورس يطابق «${search}».`}
            action={
              <Button variant="secondary" onClick={() => setSearch("")}>
                امسح البحث
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="لم تنشئ كورساً بعد"
            description="ابدأ بكورس واحد، أضف دروسه، ثم انشره لطلابك."
            action={<Button href="/manage/courses/new">أنشئ كورساً</Button>}
          />
        )
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((course) => (
            <article
              key={course.uuid}
              className="group relative overflow-hidden rounded-2xl border border-line bg-surface-raised transition hover:border-primary/40"
            >
              <Link
                href={`/manage/courses/${course.uuid}`}
                className="block rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <div className="h-32 bg-primary-soft" />
                <div className="p-5">
                  <div className="mb-2 flex flex-wrap items-center gap-2">
                    {course.is_free ? (
                      <Badge tone="success">مجاني</Badge>
                    ) : (
                      <Badge tone="warning">
                        <bdi>{formatMoney(course.price, course.currency)}</bdi>
                      </Badge>
                    )}
                    <Badge tone={statusTone(course.status)}>
                      {statusLabel(course.status)}
                    </Badge>
                  </div>
                  <h3 className="mb-1 font-semibold text-ink group-hover:text-primary-ink">
                    {course.title}
                  </h3>
                  <p className="line-clamp-2 text-sm text-ink-muted">
                    {course.description}
                  </p>
                </div>
              </Link>

              {/* Two clicks, not window.confirm(): a native dialog blocks the
                  page, cannot be translated, and cannot be tested. */}
              <div className="absolute top-3 end-3">
                {confirming === course.uuid ? (
                  <div className="flex gap-1 rounded-lg bg-surface-raised p-1 shadow-sm">
                    <Button
                      size="sm"
                      variant="danger"
                      loading={deleting === course.uuid}
                      loadingLabel="جارٍ الحذف…"
                      onClick={() => remove(course.uuid)}
                    >
                      أكّد الحذف
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setConfirming(null)}>
                      إلغاء
                    </Button>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => setConfirming(course.uuid)}
                    aria-label={`احذف كورس ${course.title}`}
                    className="rounded-lg bg-surface-raised p-1.5 text-ink-muted opacity-0 transition hover:text-danger-ink focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary group-hover:opacity-100"
                  >
                    <TrashIcon />
                  </button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
