"use client";

import { use, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";

import { CohortPicker } from "@/components/courses/CohortPicker";
import { CohortSwitcher } from "@/components/courses/CohortSwitcher";
import { CourseBanner } from "@/components/courses/CourseBanner";
import { CurriculumTree } from "@/components/courses/CurriculumTree";
import { NextSessionHeader } from "@/components/courses/NextSessionHeader";
import { AnnouncementsTab } from "@/components/courses/tabs/AnnouncementsTab";
import { AssignmentsTab } from "@/components/courses/tabs/AssignmentsTab";
import { CertificateTab } from "@/components/courses/tabs/CertificateTab";
import { ChatTab } from "@/components/courses/tabs/ChatTab";
import { ExamsTab } from "@/components/courses/tabs/ExamsTab";
import { SessionsTab } from "@/components/courses/tabs/SessionsTab";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { Tabs, TabPanel, useTabParam, type TabDefinition } from "@/components/ui/Tabs";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import { cohorts as cohortsApi, type CohortsForCourse } from "@/lib/cohorts";
import { courseHub, type CourseAnnouncement, type CourseExam } from "@/lib/course-hub";
import { curriculum, type Curriculum } from "@/lib/curriculum";
import { userMessage } from "@/lib/errors";
import type { Assignment } from "@/lib/assignments";
import type { Certificate } from "@/lib/types";

/**
 * One course: its curriculum, and everything else about it behind a tab.
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
 * ⚠️ A TAB WITH NOTHING IN IT FOR THIS COURSE IS NOT DRAWN (FR-014). A `recorded`
 * course has no sessions and never will, so it gets no sessions tab and no
 * next-session header at all — an empty tab is a promise the course cannot keep,
 * and a header saying «لا حصّة قادمة» on a course that has no lessons to schedule
 * is an answer to a question nobody asked.
 *
 * ⚠️ AND THE SIDE READS ARE ALLOWED TO FAIL WITHOUT TAKING THE PAGE DOWN. The
 * curriculum is the page; a certificates list that 500s must not blank the
 * lessons. Each side read resolves to an empty list, which — by the rule above —
 * means its tab is simply not drawn. That is the same thing the reader would see
 * if the course genuinely had no papers, and the ceiling is written down rather
 * than dressed up: a failed read is indistinguishable here from an empty one.
 * The certificate tab is the exception and survives either way, because its
 * whole job is to answer «what is left to do» when there is nothing to show.
 */

export default function CourseCurriculumPage({
  params,
}: {
  params: Promise<{ course: string }>;
}) {
  const { course: courseUuid } = use(params);

  const [data, setData] = useState<Curriculum | null>(null);
  const [sessions, setSessions] = useState<ClassSession[]>([]);
  const [nextSession, setNextSession] = useState<ClassSession | null>(null);
  const [secondsUntilStart, setSecondsUntilStart] = useState(0);
  const [secondsUntilJoinOpen, setSecondsUntilJoinOpen] = useState<number | null>(null);
  const [exams, setExams] = useState<CourseExam[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [announcements, setAnnouncements] = useState<CourseAnnouncement[]>([]);
  const [certificate, setCertificate] = useState<Certificate | null>(null);
  const [cohortState, setCohortState] = useState<CohortsForCourse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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

    // The four side reads. Each swallows its own failure into an empty list:
    // the curriculum above is the page, and a tab that cannot be filled says so
    // in its own empty state rather than replacing the lessons with an error.
    courseHub.exams(courseUuid).then((r) => setExams(r.data ?? [])).catch(() => setExams([]));
    courseHub
      .assignments(courseUuid)
      .then((r) => setAssignments(r.data ?? []))
      .catch(() => setAssignments([]));
    courseHub
      .announcements(courseUuid)
      .then((r) => setAnnouncements(r.data ?? []))
      .catch(() => setAnnouncements([]));
    courseHub
      .certificates(courseUuid)
      .then((r) => setCertificate(r.data?.[0] ?? null))
      .catch(() => setCertificate(null));
  }, [courseUuid]);

  /*
    The groups, loaded beside the curriculum rather than inside it. The gate's
    VERDICT is on the curriculum payload (`cohort_gate`) because the server is
    the only place it may be decided; this read is the list the picker draws, and
    a failure of it must not blank a page whose gate said «open».
  */
  const loadCohorts = useCallback(() => {
    cohortsApi
      .forCourse(courseUuid)
      .then(setCohortState)
      .catch(() => setCohortState(null));
  }, [courseUuid]);

  useEffect(load, [load]);
  useEffect(loadCohorts, [loadCohorts]);

  const courseType = data?.course.course_type ?? null;
  // ⚠️ A `recorded` COURSE IS NEVER ASKED ABOUT SESSIONS. Not «asked and given
  // an empty list» — there is nothing to schedule, so the request itself is the
  // wrong question, and two of them on every page load of a course that can
  // never have one.
  const hasSessions = courseType !== null && courseType !== "recorded";

  useEffect(() => {
    if (!hasSessions) return;

    /*
      ⚠️ `forCourse`, NEVER `list({ course })` — THE LATTER ANSWERS A REAL
      STUDENT `403`, AND THIS TAB WAS BUILT ON IT FOR AN AFTERNOON.

      `/class-sessions` is the teacher's calendar: its policy asks for
      `SESSIONS_VIEW`, and a student holds no spatie team id, being a member of
      no workspace. The refusal landed in the `.catch` below and the tab told a
      student with a lesson every week «لا حصص في هذه المادّة بعد» — the same
      shape as the five dead endpoints this product already shipped once, and
      invisible to a mocked component test. The route a student can use is the
      one whose guard is their enrolment, and it bounds both ends on the server
      («the oldest fifty»).
    */
    classSessions
      .forCourse(courseUuid)
      .then((r) => setSessions(r.data ?? []))
      .catch(() => setSessions([]));

    classSessions
      .nextForCourse(courseUuid)
      .then((r) => {
        setNextSession(r.data ?? null);
        setSecondsUntilStart(r.seconds_until_start ?? 0);
        setSecondsUntilJoinOpen(r.seconds_until_join_open ?? null);
      })
      .catch(() => setNextSession(null));
  }, [courseUuid, hasSessions]);

  /*
    FR-014, and it is a `useMemo` for a reason that is not performance:
    `useTabParam` re-runs its effect whenever this array's identity changes, and
    a new array on every render would fight the `?tab=` the reader typed.
  */
  /*
    The group this reader belongs to in THIS course, or null. Read from the same
    payload the picker and the switcher use — a second fetch would be a second
    answer to a question already on the page.
  */
  const cohortUuid = cohortState?.membership?.cohort_uuid ?? null;

  const tabs = useMemo<TabDefinition[]>(() => {
    const list: TabDefinition[] = [{ key: "curriculum", label: "المنهج" }];

    if (hasSessions) list.push({ key: "sessions", label: "الحصص" });
    if (exams.length > 0) list.push({ key: "exams", label: "الاختبارات" });
    if (assignments.length > 0) list.push({ key: "assignments", label: "الواجبات" });
    // The announcements tab carries a count: it is the one tab whose contents
    // change without the student doing anything.
    if (announcements.length > 0) {
      list.push({ key: "announcements", label: "التنبيهات", badge: announcements.length });
    }
    // ⚠️ ALWAYS PRESENT, UNLIKE THE OTHERS. FR-020 asks for the CONDITION when
    // there is no certificate — «what do I still have to do» is exactly the
    // question a student with no certificate is asking, so hiding the tab
    // because the answer is «none yet» removes the answer they came for.
    list.push({ key: "certificate", label: "الشهادة" });

    /*
      ⚠️ THE GROUP'S THREAD, AND ONLY FOR SOMEBODY WHO IS IN A GROUP (FR-046).
      A course with no groups has no thread — FR-036 — and a student with no
      membership has nothing to open, so the tab is absent rather than present
      and empty: a tab that answers 403 advertises a room and refuses it in one
      breath.
    */
    if (cohortUuid !== null) list.push({ key: "chat", label: "نقاش المجموعة" });

    return list;
  }, [hasSessions, exams.length, assignments.length, announcements.length, cohortUuid]);

  const [active, selectTab] = useTabParam(tabs);

  if (loading) return <RowsSkeleton />;
  if (error !== null || data === null) {
    // `userMessage()` already turned the failure into a sentence — a raw one
    // must never reach the screen, and a blank page is not the alternative.
    return <ErrorState description={error ?? undefined} onRetry={load} />;
  }

  const { course } = data;
  const gate = data.cohort_gate;

  /*
    ⚠️ THE PICKER STANDS IN FRONT OF THE CONTENT ONLY WHILE THERE IS SOMETHING TO
    PICK (FR-028أ · FR-028ب). `joinable_exists: false` means every group is full,
    closed or archived — the server has already opened every lesson, and putting
    a blocking screen over an open course would be a lock the student cannot
    clear by any action of theirs, on content they have paid for.

    The verdict is READ, never re-derived: `LessonGate` and this line have to
    agree, and two spellings of one question is the defect that made a paid-for
    recording unreachable in 018.
  */
  const mustChoose = gate.required && !gate.satisfied && gate.joinable_exists;

  if (mustChoose && cohortState !== null && Array.isArray(cohortState.cohorts)) {
    return (
      <div className="space-y-6">
        <CourseBanner
          title={course.title}
          teacherName={course.teacher_name}
          coverUrl={course.cover_url}
        />

        <CohortPicker
          options={cohortState.cohorts}
          message={gate.message}
          onJoined={() => {
            load();
            loadCohorts();
          }}
        />
      </div>
    );
  }

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

      {/* No header at all on a course that can never have a session (FR-014). */}
      {hasSessions && (
        <NextSessionHeader
          session={nextSession}
          secondsUntilStart={secondsUntilStart}
          secondsUntilJoinOpen={secondsUntilJoinOpen}
        />
      )}

      {/*
        ⚠️ AND THE SENTENCE SURVIVES THE VALVE. A course whose groups are all
        full opens completely — so without this line the reader sees an open
        course, no picker and no explanation, which reads as the groups feature
        being broken rather than as an answer.
      */}
      {gate.required && !gate.satisfied && !gate.joinable_exists && gate.message !== null && (
        <Alert tone="info" title="مجموعات هذه المادّة">{gate.message}</Alert>
      )}

      {cohortState?.membership != null && (
        <CohortSwitcher
          state={cohortState}
          onChanged={() => {
            load();
            loadCohorts();
          }}
        />
      )}

      <Tabs tabs={tabs} active={active} onChange={selectTab} label={`أقسام ${course.title}`} />

      <TabPanel tabKey="curriculum" active={active}>
        <CurriculumTree sections={data.sections} />
      </TabPanel>

      <TabPanel tabKey="sessions" active={active}>
        <SessionsTab sessions={sessions} />
      </TabPanel>

      <TabPanel tabKey="exams" active={active}>
        <ExamsTab exams={exams} />
      </TabPanel>

      <TabPanel tabKey="assignments" active={active}>
        <AssignmentsTab assignments={assignments} />
      </TabPanel>

      <TabPanel tabKey="announcements" active={active}>
        <AnnouncementsTab announcements={announcements} />
      </TabPanel>

      <TabPanel tabKey="chat" active={active}>
        <ChatTab cohortUuid={cohortUuid} />
      </TabPanel>

      <TabPanel tabKey="certificate" active={active}>
        <CertificateTab
          certificate={certificate}
          completedCount={course.completed_count}
          countableCount={course.countable_count}
          progressPct={course.progress_pct}
        />
      </TabPanel>
    </div>
  );
}
