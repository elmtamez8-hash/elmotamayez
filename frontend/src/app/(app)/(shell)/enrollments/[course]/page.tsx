"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { CourseBanner } from "@/components/courses/CourseBanner";
import { CurriculumTree } from "@/components/courses/CurriculumTree";
import { Button } from "@/components/ui/Button";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { Tabs, TabPanel, useTabParam, type TabDefinition } from "@/components/ui/Tabs";
import { curriculum, type Curriculum } from "@/lib/curriculum";
import { userMessage } from "@/lib/errors";

/**
 * One course, as a curriculum.
 *
 * The segment is the COURSE uuid, not the enrolment's — it is what the page
 * linking here holds, and the server finds the enrolment from the viewer.
 *
 * ⚠️ THE WHOLE POINT IS THAT THE ANSWER ARRIVES BEFORE THE TAP. This screen used
 * to list every lesson as a link, whatever its state: a student discovered a lock
 * by pressing a row, waiting for a page, and reading a refusal on something they
 * could not use. The gate has always known why — `LessonAccess` has carried a
 * reason since 016 — and nothing had ever put it beside the item.
 *
 * ⚠️ AND THE TAB STRIP IS ONE TAB DEEP ON PURPOSE. The other five arrive with
 * US2; the strip is here now so the curriculum is not re-parented later, which is
 * how a page ends up with two layouts and a scroll position that jumps.
 */

const TABS: TabDefinition[] = [{ key: "curriculum", label: "المنهج" }];

export default function CourseCurriculumPage({
  params,
}: {
  params: Promise<{ course: string }>;
}) {
  const { course: courseUuid } = use(params);

  const [data, setData] = useState<Curriculum | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [active, selectTab] = useTabParam(TABS);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    curriculum(courseUuid)
      .then(setData)
      // ⚠️ NOT `.catch(() => undefined)`. Swallowing this renders a permanently
      // blank page with the reason sitting unread in the response — and the rule
      // against showing a raw error is not a rule for showing nothing.
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [courseUuid]);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton />;
  if (error !== null || data === null) {
    // `userMessage()` already turned the failure into a sentence — a raw one
    // must never reach the screen, and a blank page is not the alternative.
    return <ErrorState description={error ?? undefined} onRetry={load} />;
  }

  const { course } = data;

  return (
    <div className="space-y-6">
      <Link
        href="/enrollments"
        className="inline-block rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        ← تعلّمي
      </Link>

      <CourseBanner
        title={course.title}
        teacherName={course.teacher_name}
        coverUrl={course.cover_url}
      >
        <div className="max-w-md space-y-2">
          <ProgressBar value={course.progress_pct} label={`تقدّمك في ${course.title}`} />

          <p className="text-sm text-white/85">
            {/* `bdi` so the digits do not drag the surrounding Arabic around. */}
            أتممتَ <bdi>{course.completed_count}</bdi> من <bdi>{course.countable_count}</bdi>{" "}
            — <bdi>{course.progress_pct}%</bdi>
          </p>

          {/*
            «تابعْ من هنا» (FR-010). Absent rather than disabled when there is
            nothing to resume: a finished course and a course whose first item is
            shut are both real, and a button that answers 403 is worse than none.
          */}
          {course.resume_lesson_uuid !== null && (
            // `Button`, not a hand-rolled `bg-white` pill: colour comes from
            // the closed variant set, and `bg-white` is banned outright — the
            // theme has a `surface-raised` token that is correct in both themes,
            // where a literal white is a light-mode assumption baked into a
            // component that renders in both.
            <Button href={`/learn/${course.resume_lesson_uuid}`} variant="secondary">
              تابعْ من هنا
            </Button>
          )}
        </div>
      </CourseBanner>

      <Tabs
        tabs={TABS}
        active={active}
        onChange={selectTab}
        label={`أقسام ${course.title}`}
      />

      <TabPanel tabKey="curriculum" active={active}>
        <CurriculumTree sections={data.sections} />
      </TabPanel>
    </div>
  );
}
