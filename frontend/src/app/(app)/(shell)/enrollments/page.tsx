"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import type { Enrollment } from "@/lib/types";
import { formatDate, statusLabel, statusTone, TONE_CLASSES } from "@/lib/labels";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { Alert } from "@/components/ui/Alert";
import { conversations } from "@/lib/conversations";
import { userMessage } from "@/lib/errors";

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

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">تعلّمي</h2>

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
          action={
            <Link
              href="/courses"
              className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              تصفّح الكورسات
            </Link>
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {enrollments.map((enr) => (
            <article
              key={enr.uuid}
              className="rounded-xl border border-line bg-surface-raised p-5"
            >
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
                <span
                  className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                    TONE_CLASSES[statusTone(enr.status)]
                  }`}
                >
                  {statusLabel(enr.status)}
                </span>
              </div>

              <div className="mb-3">
                <div className="mb-1 flex justify-between text-xs text-ink-muted">
                  <span>نسبة الإنجاز</span>
                  <span>
                    <bdi>{enr.progress_pct}%</bdi>
                  </span>
                </div>
                <div
                  className="h-2 overflow-hidden rounded-full bg-line"
                  role="progressbar"
                  aria-valuenow={enr.progress_pct}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label="نسبة الإنجاز"
                >
                  <div
                    className={`h-full ${
                      enr.status === "completed" ? "bg-secondary" : "bg-primary"
                    }`}
                    style={{ width: `${enr.progress_pct}%` }}
                  />
                </div>
              </div>

              <p className="text-xs text-ink-muted">
                سُجِّل في {formatDate(enr.enrolled_at)}
              </p>

              {/* Spec 010 · US2 — the ONLY door into the private chat.
                  ⚠️ It is here and not on a directory of teachers, because a
                  student's enrolments are the list of people they may write to;
                  a second list would answer that question in a different voice
                  from the endpoint. One conversation per teacher, so opening it
                  twice returns the same one. */}
              {enr.workspace_uuid !== null && enr.workspace_uuid !== undefined && (
                <button
                  type="button"
                  onClick={() => openChat(enr.workspace_uuid as string)}
                  disabled={opening === enr.workspace_uuid}
                  className="mt-3 rounded-xl border border-line px-3 py-1.5 text-xs font-medium text-primary-ink transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60"
                >
                  {`راسل ${enr.teacher_name ?? "المدرّس"}`}
                </button>
              )}
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
