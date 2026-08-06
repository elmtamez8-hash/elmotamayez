"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { SeatBadge } from "@/components/sessions/SeatBadge";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { AttendanceSheet } from "@/components/sessions/AttendanceSheet";
import {
  attendance,
  classSessions,
  recordingLabel,
  type AttendanceRow,
  type ClassSession,
} from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { formatSessionTime } from "@/lib/session-format";

/** One session: its seats, its room, and — once it has run — its register. */
export default function ManageSessionPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);

  const [session, setSession] = useState<ClassSession | null>(null);
  const [rows, setRows] = useState<AttendanceRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [cancelling, setCancelling] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([classSessions.show(uuid), attendance.list(uuid)])
      .then(([detail, register]) => {
        setSession(detail);
        setRows(register.data ?? []);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  const cancel = async () => {
    setCancelling(true);
    setError("");

    try {
      setSession(await classSessions.cancel(uuid));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setCancelling(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed || session === null) return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/manage/sessions"
          className="rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          ← حصصي
        </Link>
        <h2 className="mt-1 text-2xl font-bold text-ink">{session.title}</h2>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر الإجراء">
          {error}
        </Alert>
      )}

      <Card>
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <Badge tone={session.status === "cancelled" ? "danger" : "info"}>
            {session.status_label}
          </Badge>
          <Badge tone="neutral">{session.type_label}</Badge>
          <SeatBadge seats={session.seats} />
        </div>

        <p className="mb-1 text-sm text-ink-muted">
          {formatSessionTime(session.starts_at, session.timezone)}
        </p>
        <p className="mb-4 text-sm text-ink-muted">
          المدة <bdi>{session.duration_minutes}</bdi> دقيقة · المنطقة الزمنية{" "}
          <bdi>{session.timezone}</bdi>
        </p>

        <div className="flex flex-wrap gap-3">
          {/* The room is reachable from here and from the student's card — there
              is no nav entry for it, because a room without a session is not a
              place. */}
          <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>

          {session.status === "scheduled" && (
            <Button onClick={cancel} loading={cancelling} variant="danger">
              إلغاء الحصة
            </Button>
          )}
        </div>
      </Card>

      <Card>
        <h3 className="mb-3 font-semibold text-ink">كشف الحضور</h3>
        <AttendanceSheet rows={rows} canOverride onChanged={load} />
      </Card>

      {session.recording !== null && (
        <Card>
          <h3 className="mb-2 font-semibold text-ink">التسجيل</h3>
          <p className="mb-3 text-sm text-ink-muted">{recordingLabel(session.recording.status)}</p>

          {/* A published recording without a way in is a lesson nobody can
              reach — the exact bug this project has already shipped once. */}
          {session.recording.lesson_uuid !== null && (
            <Button href={`/learn/${session.recording.lesson_uuid}`} variant="secondary">
              مشاهدة التسجيل
            </Button>
          )}
        </Card>
      )}
    </div>
  );
}
