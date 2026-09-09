"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney, statusLabel, statusTone } from "@/lib/labels";
import type { Course } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextField } from "@/components/ui/Field";
import { TrashIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

/**
 * ⚠️ ONE REQUEST FOR THE WHOLE SET, AND THE FILTERS ARE BUILT FROM IT.
 * Facets derived from a page of fifteen offer only what that page happened to
 * contain — a filter that silently lies about what the teacher owns. Measured
 * 2026-09-09: the largest workspace on the platform holds 82 courses and every
 * other one holds four or fewer, so the whole set is one modest request.
 *
 * ponytail: 200 is the server's ceiling and the banner below says when it bites.
 * Past that the honest fix is server-side filtering with facets computed over
 * the whole set — not a bigger number here.
 */
const PAGE_SIZE = 200;

/** The value standing for «filed under no stage» — 95 of 96 courses, today. */
const NO_STAGE = "__none";

export default function ManageCoursesPage() {
  const [courses, setCourses] = useState<Course[]>([]);
  const [total, setTotal] = useState(0);
  const [stageNames, setStageNames] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [stage, setStage] = useState("");
  const [subject, setSubject] = useState("");
  /** uuid awaiting a second click to confirm deletion. */
  const [confirming, setConfirming] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Course[]; meta?: { total?: number } }>(`/courses?per_page=${PAGE_SIZE}`)
      .then((res) => {
        const rows = res.data ?? [];
        setCourses(rows);
        setTotal(res.meta?.total ?? rows.length);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  /*
    The Arabic names of the stages, from the catalogue that owns them. The API
    sends `courses.grade_level` as a bare slug — the same undefended text the
    settlement-rate key carries — and restating the five labels in TypeScript
    would be a second vocabulary that ages at the first rename.
  */
  useEffect(() => {
    api
      .get<{ data: { slug: string; name_ar: string }[] }>("/signup/grade-levels")
      .then((res) =>
        setStageNames(
          Object.fromEntries((res.data ?? []).map((row) => [row.slug, row.name_ar])),
        ),
      )
      .catch(() => setStageNames({}));
  }, []);

  /*
    ⚠️ THE OPTIONS COME FROM THE COURSES, NOT FROM THE TEACHER'S PROFILE.
    A teacher who declared three subjects on their application but has courses in
    one has nothing to filter by — and one approved for a single subject who
    later authored in another would be offered a filter that hides half their
    work. The set on the screen is the only honest source for the set of filters
    over it.
  */
  const stageOptions = useMemo(() => {
    const present = new Set(courses.map((c) => c.grade_level ?? NO_STAGE));

    return [...present].map((slug) => ({
      value: slug,
      label: slug === NO_STAGE ? "بلا مرحلة" : (stageNames[slug] ?? slug),
    }));
  }, [courses, stageNames]);

  const subjectOptions = useMemo(() => {
    const byUuid = new Map<string, string>();

    for (const course of courses) {
      if (course.subject) byUuid.set(course.subject.uuid, course.subject.label);
    }

    return [...byUuid].map(([value, label]) => ({ value, label }));
  }, [courses]);

  // A select offering one answer is not a filter — it is a control that can only
  // ever return everything.
  const showStage = stageOptions.length > 1;
  const showSubject = subjectOptions.length > 1;
  const filtering = search !== "" || stage !== "" || subject !== "";

  const filtered = useMemo(
    () =>
      courses.filter((course) => {
        if (search && !course.title.toLowerCase().includes(search.toLowerCase())) return false;
        if (stage && (course.grade_level ?? NO_STAGE) !== stage) return false;
        if (subject && course.subject?.uuid !== subject) return false;

        return true;
      }),
    [courses, search, stage, subject],
  );

  const clearFilters = () => {
    setSearch("");
    setStage("");
    setSubject("");
  };

  const remove = async (uuid: string) => {
    setDeleting(uuid);
    setError("");
    try {
      await api.delete(`/courses/${uuid}`);
      setCourses((prev) => prev.filter((c) => c.uuid !== uuid));
      setTotal((prev) => Math.max(prev - 1, 0));
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
          <p className="text-ink-muted">
            {loading
              ? "أنشئ كورساتك وحرّرها وانشرها."
              : `${courses.length} كورساً · أنشئها وحرّرها وانشرها.`}
          </p>
        </div>
        <Button href="/manage/courses/new">كورس جديد</Button>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {total > courses.length && (
        <Alert tone="warning" title={`تُعرض أول ${courses.length} كورساً من ${total}.`}>
          ابحث بالاسم للوصول إلى البقية.
        </Alert>
      )}

      {!loading && !failed && courses.length > 0 && (
        <Card as="section">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <TextField
              id="course_search"
              label="ابحث في كورساتك"
              type="search"
              value={search}
              onChange={setSearch}
              placeholder="اسم الكورس"
            />
            {showStage && (
              <SelectField
                id="filter_stage"
                label="المرحلة الدراسية"
                value={stage}
                onChange={setStage}
                placeholder="كل المراحل"
                options={stageOptions}
              />
            )}
            {showSubject && (
              <SelectField
                id="filter_subject"
                label="المادّة"
                value={subject}
                onChange={setSubject}
                placeholder="كل المواد"
                options={subjectOptions}
              />
            )}
          </div>
          {filtering && (
            <div className="mt-4">
              <Button size="sm" variant="ghost" onClick={clearFilters}>
                امسح الفلاتر
              </Button>
            </div>
          )}
        </Card>
      )}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : filtered.length === 0 ? (
        // "No results for your filter" is not "you have no courses": the first
        // needs the filter cleared, the second needs a course created. Offering
        // the wrong one leaves the user staring at data they cannot see.
        filtering ? (
          <EmptyState
            title="لا نتائج تطابق الفلاتر"
            description="لا كورس يطابق ما اخترته."
            action={
              <Button variant="secondary" onClick={clearFilters}>
                امسح الفلاتر
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
              className="group relative flex flex-col overflow-hidden rounded-2xl border border-line bg-surface-raised transition hover:border-primary/40"
            >
              <Link
                href={`/manage/courses/${course.uuid}`}
                className="block rounded-t-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <div className="h-28 bg-primary-soft" />
                <div className="p-5 pb-4">
                  <div className="mb-2 flex flex-wrap items-center gap-2">
                    <Badge tone={statusTone(course.status)}>{statusLabel(course.status)}</Badge>
                    {course.is_free ? (
                      <Badge tone="success">مجاني</Badge>
                    ) : (
                      <Badge tone="warning">
                        <bdi>{formatMinorMoney(course.price_minor, course.currency)}</bdi>
                      </Badge>
                    )}
                  </div>
                  <h3 className="mb-1 font-semibold text-ink group-hover:text-primary-ink">
                    {course.title}
                  </h3>
                  <p className="line-clamp-2 text-sm text-ink-muted">{course.description}</p>

                  {/* The two facts the filters above narrow by, on the card that
                      gets narrowed — a filter whose criterion is invisible on the
                      result leaves the reader guessing why a course is missing. */}
                  <p className="mt-3 text-xs text-ink-muted">
                    {course.subject?.label ?? "بلا مادّة"}
                    {" · "}
                    {course.grade_level
                      ? (stageNames[course.grade_level] ?? course.grade_level)
                      : "بلا مرحلة"}
                  </p>
                </div>
              </Link>

              {/* ⚠️ OUTSIDE THE LINK ABOVE. A card-wide anchor with a second
                  anchor inside it is invalid HTML, and browsers recover from it
                  by dropping one of the two — usually the inner one, which is
                  the link a teacher is actually reaching for. */}
              <div className="mt-auto border-t border-line px-5 py-4">
                <div className="mb-2 flex items-center justify-between gap-2">
                  <h4 className="text-xs font-semibold text-ink">المجموعات ومواعيدها</h4>
                  <Link
                    href={`/manage/courses/${course.uuid}/cohorts`}
                    className="rounded text-xs text-primary-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    إدارة
                  </Link>
                </div>

                {(course.cohorts ?? []).length === 0 ? (
                  <p className="text-xs text-ink-muted">لا مجموعات بعد.</p>
                ) : (
                  <ul className="space-y-2">
                    {(course.cohorts ?? []).slice(0, 3).map((cohort) => (
                      <li key={cohort.uuid} className="text-xs">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium text-ink">{cohort.name}</span>
                          <Badge tone={statusTone(cohort.status)}>
                            {statusLabel(cohort.status)}
                          </Badge>
                          {/* ⚠️ NULL IS NOT ZERO. Null means the group declared no
                              ceiling; printing it as «٠ مقاعد» would render the
                              most open group in the course as the one nobody can
                              join. */}
                          {cohort.seats_left !== null && (
                            <span className="text-ink-muted">
                              {cohort.seats_left} مقعداً متاحاً
                            </span>
                          )}
                        </div>
                        <p className="text-ink-muted">
                          {cohort.schedule_preview.length > 0
                            ? cohort.schedule_preview.join(" · ")
                            : "لا مواعيد مجدولة بعد"}
                        </p>
                      </li>
                    ))}
                    {(course.cohorts ?? []).length > 3 && (
                      <li className="text-xs text-ink-muted">
                        و{(course.cohorts ?? []).length - 3} مجموعة أخرى.
                      </li>
                    )}
                  </ul>
                )}
              </div>

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
