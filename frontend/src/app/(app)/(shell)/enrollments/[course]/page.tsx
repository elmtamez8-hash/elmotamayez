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
import { RosterTab } from "@/components/courses/tabs/RosterTab";
import { SessionsTab } from "@/components/courses/tabs/SessionsTab";
import { HistoryIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { api } from "@/lib/api";
import { counted } from "@/lib/labels";
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
import { arabicNumber } from "@/lib/numerals";

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
  const [resetAsking, setResetAsking] = useState(false);
  const [resetting, setResetting] = useState(false);
  const [resetError, setResetError] = useState("");

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
    إعادةُ الكورسِ من أوّله (طلبُ المالكِ ٢٠٢٦-٠٩-١٤).

    ⚠️ **المعرّفُ من الحمولةِ لا من المسار.** مسارُ هذه الصفحةِ هو معرّفُ
    الكورس، والبابُ `/enrollments/{enrollment}/reset` — فـ`enrollment_uuid`
    أُضيفَ إلى `CurriculumResource` لهذا، وبدونِه يذهبُ `undefined` في الرابطِ
    بلا خطأٍ في أيِّ مكان.

    و`load()` بعدَها: النسبةُ والعدّادُ و«تابعْ من هنا» وكلُّ حالةِ صفٍّ في
    الشجرةِ قراراتُ الخادمِ، وقلبُها هنا تفاؤلاً هجاءٌ ثانٍ لِما يُقرّرُه
    `Enrollment::accessTo()`.
  */
  const resetCourse = async (enrollmentUuid: string) => {
    setResetting(true);
    setResetError("");

    try {
      await api.post(`/enrollments/${enrollmentUuid}/reset`);
      setResetAsking(false);
      load();
    } catch (err: unknown) {
      setResetError(userMessage(err));
      setResetAsking(false);
    } finally {
      setResetting(false);
    }
  };

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

  /*
    ⛔ **THE SCHEDULE, NOT THE DECLARATION — AND THE TWO ARE DIFFERENT QUESTIONS.**

    This was `course_type !== "recorded"`, and `courses.course_type` had NO
    WRITER ANYWHERE from 2026-08-01 to 2026-09-15: it carried a DB default that
    read as a decision. So «Laravel Mastery» on production — eight live sessions
    ahead of it, an open group, four enrolled students — drew no «الحصص» tab and
    no next-session countdown at all, and nothing anywhere said why.

    ⚠️ **THE COLUMN HAS A WRITER NOW AND THAT STILL IS NOT ENOUGH**, because the
    failure directions are not symmetric: a teacher who mislabels their course
    hides the timetable from their own students SILENTLY — no error, no badge,
    nobody sees it — while the same mistake in the marketplace is a visible wrong
    label somebody reports. So the tab follows what is actually scheduled, and
    the declaration keeps the badge and the filter. The teacher is told when the
    two disagree, on `/manage/courses/{uuid}/edit`.

    The original reason for the flag survives intact: a course with nothing
    scheduled is still never ASKED about sessions — two requests on every load of
    a page that has none to show.
  */
  const hasSessions = data?.course.has_sessions ?? false;

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
    if (cohortUuid !== null) {
      list.push({ key: "chat", label: "نقاش المجموعة" });
      // The same condition, and for the same reason: a course with no groups has
      // no classmates to name, and a tab that answers 403 advertises a list and
      // refuses it in one breath. The door is narrower than the thread's — an
      // OPEN membership — so a student who transferred sees their new group here
      // while the old thread stays readable (FR-046 · FR-050).
      list.push({ key: "roster", label: "الزملاء" });
    }

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
    ⛔ **لا شاشةَ حاجزةً بعدَ اليوم — ٠٣٤ · FR-015 · FR-012.**

    كانَ هنا `mustChoose`: متى وُجِدَت مجموعةٌ قابلةٌ للانضمامِ ولم يكنِ الطالبُ
    في واحدة، يُرسَمُ المُنتقي وحدَهُ ويُحجَبُ المنهجُ كلُّهُ خلفَه. و٠٣٤ · FR-015
    **تُلغي ذلكَ الشرطَ نصّاً** («يُلغي هذا شرطَ ٠٢١ · FR-028أ»)، والطالبُ
    لم يعُدْ هو من يختار: الإدارةُ تُسنِد، والجملةُ تعلو المنهجَ **المفتوح**.

    ⛔ **وكانَ القفلُ رجعيّاً، وقِيسَ على الإنتاج**: إنشاءُ أوّلِ مجموعةٍ
    لكورسٍ قائمٍ كانَ يُغلِقُ منهجَ كلِّ من سجّلَ قبلَها في اللحظةِ نفسِها.

    ⚠️ **والمُنتقي يبقى معروضاً حتّى T030/T035**، غيرَ حاجز. وذلكَ مقصود:
    مسارُ `POST /cohorts/{cohort}/join` ما زالَ قائماً، وحذفُ الزرِّ قبلَ بناءِ
    شاشةِ الإسنادِ (US1) يتركُ الطالبَ بلا مخرجٍ إطلاقاً — «لا يُغلَقُ بابٌ
    قبلَ بناءِ البابِ الذي يحلُّ محلَّه».
  */

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
            أتممتَ <bdi>{arabicNumber(course.completed_count)}</bdi> من <bdi>{arabicNumber(course.countable_count)}</bdi>{" "}
            — <bdi>{arabicNumber(course.progress_pct)}٪</bdi>
          </p>

          {/*
            ٠٣٥ · FR-025 — «كذا حصّةً مقفولة»، بجانبِ الرقمِ الذي تُفسِّرُه.
            ⛔ نسبةٌ تقفُ دونَ ١٠٠٪ بلا سببٍ هي العطبُ لا القفلُ نفسُه: الشرطُ
            الثالثُ غيرُ القابلِ للتأجيلِ في المواصفةِ هو أن يُقالَ للطالبِ ما
            الناقصُ وكيف يبلغُه، لا أن يُترَكَ أمامَ رقمٍ يتوقّف.
            ⚠️ والعددُ عبرَ `counted()` بخانةِ `other` صريحة: «١٠٠ حصة واحدة»
            أسوأُ من القالبِ الذي حلَّ محلَّه.
          */}
          {course.locked_session_count > 0 && (
            <p className="text-sm text-white/85">
              {counted(course.locked_session_count, {
                one: "حصة واحدة مقفولة",
                two: "حصتان مقفولتان",
                few: "حصص مقفولة",
                many: "حصة مقفولة",
                other: "حصة مقفولة",
              })}{" "}
              — افتحْ كلَّ واحدةٍ منها بخصمِ حصّةٍ من رصيدِك من صفحةِ الحصّة.
            </p>
          )}

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

          {/*
            «ابدأْه من جديد» — ولا يُرسَمُ على كورسٍ لم يُتِمَّ صاحبُه فيه شيئاً:
            زرٌّ يُعيدُ صفراً إلى صفرٍ سؤالٌ بلا جواب.
          */}
          {course.completed_count > 0 && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              iconStart={<HistoryIcon className="h-4 w-4" aria-hidden="true" />}
              onClick={() => setResetAsking(true)}
            >
              ابدأِ الكورس من جديد
            </Button>
          )}

          {resetError !== "" && (
            <Alert tone="danger" title="لم تتم الإعادة">
              {resetError}
            </Alert>
          )}

          {/*
            ⚠️ **ثلاثُ نتائجَ تُقالُ قبلَ الضغطِ لا بعدَه**، وكلُّ واحدةٍ منها
            سؤالُ طالبٍ حقيقيّ: النسبةُ ترجعُ صفراً، وما بعدَ الأوّلِ يُقفَلُ في
            كورسٍ متسلسل، والشهادةُ **تبقى** — وهي أوّلُ ما يُخافُ عليه، فالصمتُ
            عنها وحدَه كافٍ لألّا يضغطَ أحد.
            ⚠️ وعنصرُ الاختبارِ يبقى مكتملاً: الخادمُ يتخطّاه لأنَّ محاولاتِه
            محدودة، فالقولُ «كلُّ شيءٍ يرجع» وعدٌ يكسِرُه الخادمُ بحقّ.
          */}
          <Modal
            open={resetAsking}
            title="تبدأ هذا الكورس من جديد؟"
            message={
              (course.is_sequential
                ? "ترجع نسبتك إلى ٠٪، ويُقفل كل درس بعد الأول حتى تُتمّه من جديد."
                : "ترجع نسبتك إلى ٠٪، ويرجع كل درس أتممتَه غير مكتمل.") +
              "\nشهادتك — إن صدرت — تبقى صالحة كما هي، ولا تُصدر ثانية." +
              "\nونتيجة أي اختبار أدّيتَه تبقى كما هي."
            }
            confirmLabel="ابدأْ من جديد"
            busy={resetting}
            onConfirm={() => void resetCourse(course.enrollment_uuid)}
            onCancel={() => setResetAsking(false)}
          />
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
        ⛔ **الجملةُ فوقَ منهجٍ مفتوح، وفي الحالتَينِ معاً** (FR-015 · FR-012).

        كانَ الشرطُ `!gate.joinable_exists` وحدَه — أي أنَّ الجملةَ لا تُقالُ إلّا
        لمن لا يستطيعُ الانضمام، لأنَّ الآخَرَ كانَ يراها على الشاشةِ الحاجزة. وقد
        زالَت الحاجزةُ، فبقاءُ الشرطِ يتركُ الأكثريّةَ أمامَ منهجٍ مفتوحٍ بلا
        تفسيرٍ لماذا لا مجموعةَ لهم — وهو ما يُقرَأُ عُطلاً. والنصُّ من الخادمِ لا
        يُشتَقُّ هنا: إملاءانِ لسؤالٍ واحدٍ هو ما جعلَ تسجيلاً مدفوعاً غيرَ قابلٍ
        للفتحِ في ٠١٨.
      */}
      {gate.message !== null && (
        <Alert tone="info" title="مجموعتك في هذه المادّة">{gate.message}</Alert>
      )}

      {/*
        والمُنتقي تحتَها وفوقَ المنهجِ — عرضاً لا بوّابةً. ولا يُرسَمُ على
        قائمةٍ فارغة: بطاقةٌ خاويةٌ فوقَ منهجٍ مفتوحٍ ضجيجٌ لا جواب.
      */}
      {gate.required &&
        !gate.satisfied &&
        cohortState !== null &&
        Array.isArray(cohortState.cohorts) &&
        cohortState.cohorts.length > 0 && (
          <CohortPicker
            options={cohortState.cohorts}
            message={null}
            onJoined={() => {
              load();
              loadCohorts();
            }}
          />
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
        {/* السببُ من الخادم، لا يُشتقُّ هنا — انظر `SessionsTab`. */}
        <SessionsTab sessions={sessions} unplacedReason={gate.message} />
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
        <ChatTab cohortUuid={cohortUuid} pastCohorts={cohortState?.past_cohorts ?? []} />
      </TabPanel>

      <TabPanel tabKey="roster" active={active}>
        <RosterTab cohortUuid={cohortUuid} />
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
