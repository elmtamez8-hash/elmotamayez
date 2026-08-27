"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import type { Enrollment } from "@/lib/types";
import { formatDate } from "@/lib/labels";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import {
  AcademicCapIcon,
  CertificateIcon,
  ChevronEndIcon,
  LearningIcon,
  MessagesIcon,
  ScheduleIcon,
} from "@/components/icons";
import { conversations } from "@/lib/conversations";
import { userMessage } from "@/lib/errors";

/**
 * The student's own courses.
 *
 * ⚠️ SPLIT INTO «جارية» AND «مكتملة», the same idiom `/schedule` groups its days
 * with. This page is opened to answer «فين وصلت؟», and a finished course sitting
 * between two live ones is a row the reader has to identify and skip on the way
 * to the one they wanted. The split also lets the finished group carry its own
 * tone — green bars and a certificate glyph — without colour being the only
 * thing saying so.
 */
export default function EnrollmentsPage() {
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const router = useRouter();
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [opening, setOpening] = useState<string | null>(null);
  const [chatProblem, setChatProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Enrollment[] }>("/enrollments")
      .then((res) => setEnrollments(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  /*
   * One conversation per teacher: the endpoint returns the open one when there
   * is one, so this button is safe to press twice — and safe to press from two
   * devices at once, which is the race `StartConversation` declares.
   */
  const openChat = (workspaceUuid: string) => {
    setOpening(workspaceUuid);
    setChatProblem(null);

    conversations
      .start(workspaceUuid)
      .then((conversation) => router.push(`/messages/${conversation.uuid}`))
      // Never a raw error — and never a swallowed one either.
      .catch((error: unknown) => setChatProblem(userMessage(error)))
      .finally(() => setOpening(null));
  };

  /*
   | ⚠️ THE STATUS, NOT THE PERCENTAGE. `progress_pct` reaching 100 and the
   | enrolment being `completed` are two different facts — the second is written
   | by `CourseCompleted`, and the denominator can move under the first (a
   | recording archived, an item published) which is the family of defect
   | `ResyncCourseProgress` exists for. Reading the bar to decide the group would
   | file a course as finished that the certificate flow has not agreed is.
   */
  const [live, done] = useMemo(() => {
    const finished = enrollments.filter((row) => row.status === "completed");

    return [enrollments.filter((row) => row.status !== "completed"), finished];
  }, [enrollments]);

  const card = (enr: Enrollment, index: number) => {
    const completed = enr.status === "completed";

    return (
      /*
        ⚠️ THE STAGGER IS CAPPED AND THE FILL MODE IS `both`. `banner-rise`
        covers the delay as well as the animation, so a card is not painted,
        hidden when its turn comes and repainted — the flicker that only shows on
        staggered elements. The reduced-motion block in globals.css zeroes it.
      */
      <div
        key={enr.uuid}
        className="banner-rise"
        style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
      >
        <article className="flex h-full flex-col rounded-3xl border border-line bg-surface-raised p-5 transition-colors duration-200 hover:border-primary/40 focus-within:border-primary/40">
          <div className="mb-3 flex items-start justify-between gap-3">
            <h3 className="font-semibold text-ink">
              {/* The only way into the lessons — and from there into the
                  player. Without it the whole watching flow is a route
                  nothing points at. */}
              <Link
                href={`/enrollments/${enr.course_uuid}`}
                className="rounded hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                {enr.course_title}
              </Link>
            </h3>
            <StatusBadge status={enr.status} />
          </div>

          {enr.teacher_name !== null && (
            /*
              ⚠️ THE TEACHER'S NAME ON THE CARD, not only inside the chat
              button's label. A student studying the same subject with two
              teachers had no way to tell the two rows apart until they read a
              button — and the button is absent whenever `workspace_uuid` is,
              which is exactly the row that then has no name on it at all.
            */
            <p className="mb-3 flex items-center gap-1.5 text-sm text-ink-muted">
              {/* `className` REPLACES the icon's default size, so it is repeated. */}
              <AcademicCapIcon className="h-4 w-4 shrink-0" />
              {/* ⚠️ «عند», because `teacher_name` IS THE WORKSPACE NAME — the
                  field is named for the question it answers, not its column, and
                  a workspace is an academy as often as it is one person. The
                  name alone under a graduation cap claims to be a teacher; the
                  preposition is true of «Demo Academy» and of «أ. سامي» both. */}
              عند {enr.teacher_name}
            </p>
          )}

          <div className="mb-4">
            <div className="mb-1.5 flex items-baseline justify-between gap-2 text-xs text-ink-muted">
              <span>نسبة الإنجاز</span>
              <span className="text-base font-bold tabular-nums text-ink">
                <bdi>{enr.progress_pct}%</bdi>
              </span>
            </div>
            <ProgressBar
              value={enr.progress_pct}
              label="نسبة الإنجاز"
              tone={completed ? "secondary" : "primary"}
            />
          </div>

          <p className="mb-4 flex items-center gap-1.5 text-xs text-ink-muted">
            <ScheduleIcon className="h-4 w-4 shrink-0" />
            سُجِّل في {formatDate(enr.enrolled_at)}
          </p>

          {/* `mt-auto` so the actions line up across cards of different heights —
              a row of buttons at three different heights reads as three
              unrelated panels. */}
          <div className="mt-auto flex flex-wrap items-center gap-2">
            <Button
              href={`/enrollments/${enr.course_uuid}`}
              size="sm"
              variant={completed ? "secondary" : "primary"}
              iconEnd={<ChevronEndIcon className="h-4 w-4" />}
            >
              {completed ? "راجعِ الكورس" : enr.progress_pct > 0 ? "تابعِ التعلّم" : "ابدأ الآن"}
            </Button>

            {/* Spec 010 · US2 — the ONLY door into the private chat.
                ⚠️ It is here and not on a directory of teachers, because a
                student's enrolments are the list of people they may write to;
                a second list would answer that question in a different voice
                from the endpoint. One conversation per teacher, so opening it
                twice returns the same one. */}
            {enr.workspace_uuid !== null && enr.workspace_uuid !== undefined && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => openChat(enr.workspace_uuid as string)}
                loading={opening === enr.workspace_uuid}
                loadingLabel="جارٍ الفتح…"
                iconStart={<MessagesIcon className="h-4 w-4" />}
              >
                {`راسل ${enr.teacher_name ?? "المدرّس"}`}
              </Button>
            )}
          </div>
        </article>
      </div>
    );
  };

  const group = (
    title: string,
    icon: React.ReactNode,
    rows: Enrollment[],
    offset: number,
  ) =>
    rows.length === 0 ? null : (
      <section className="space-y-3">
        <h3 className="flex items-center gap-3 text-sm font-semibold text-ink-muted">
          <span className="flex items-center gap-1.5">
            {icon}
            {title}
          </span>
          {/* A rule to the end of the row, so the eye finds where one group
              stops without a second border competing with the cards' own. */}
          <span aria-hidden className="h-px flex-1 bg-line" />
          <span className="text-xs font-normal">
            <bdi>{rows.length}</bdi> كورس
          </span>
        </h3>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {rows.map((enr, index) => card(enr, index + offset))}
        </div>
      </section>
    );

  return (
    <div className="space-y-6">
      <h2 className="flex items-center gap-2 text-2xl font-bold text-ink">
        <LearningIcon className="h-6 w-6 text-primary-ink" />
        تعلّمي
      </h2>

      {chatProblem !== null && (
        <Alert tone="danger" title="تعذّر فتح المحادثة">
          {chatProblem}
        </Alert>
      )}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : enrollments.length === 0 ? (
        <EmptyState
          title="لم تسجّل في أي كورس بعد"
          description="اختر كورساً من السوق وابدأ أول درس اليوم."
          // `Button`, not a hand-rolled `bg-primary px-5 py-2.5` — that string is
          // how a product ends up with four maroons, and this one had drifted to
          // a 12px corner beside the pills everywhere else.
          action={<Button href="/courses">تصفّح الكورسات</Button>}
        />
      ) : (
        <div className="space-y-6">
          {group("جارية", <LearningIcon className="h-4 w-4" />, live, 0)}
          {group("مكتملة", <CertificateIcon className="h-4 w-4" />, done, live.length)}
        </div>
      )}
    </div>
  );
}
