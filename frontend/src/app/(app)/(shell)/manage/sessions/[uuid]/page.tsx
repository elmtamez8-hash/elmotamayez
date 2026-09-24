"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { SeatBadge } from "@/components/sessions/SeatBadge";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { NumberField, TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { PlayIcon, SessionsIcon, SettingsIcon, UsersIcon } from "@/components/icons";
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
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { timezoneLabel } from "@/lib/labels";
import { formatSessionTime } from "@/lib/session-format";

/**
 * Statuses with no room to enter, whatever `room_closed` says. Cancelling never
 * stamps `room_closed_at`, so the button survived a cancel and led the teacher
 * to a refusal worded for a student.
 */
const ROOMLESS = new Set(["cancelled", "completed", "interrupted", "suspended"]);

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
  const [askingCancel, setAskingCancel] = useState(false);
  const [edit, setEdit] = useState({ title: "", seats: "" });
  const [saving, setSaving] = useState(false);
  const [editErrors, setEditErrors] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([classSessions.show(uuid), attendance.list(uuid)])
      .then(([detail, register]) => {
        setSession(detail);
        setRows(register.data ?? []);
        // Seeded from what the server just said, so the form starts as the
        // truth rather than as a blank that would save an empty title.
        setEdit({ title: detail.title, seats: String(detail.seats.total) });
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
      setAskingCancel(false);
    }
  };

  const save = async () => {
    setSaving(true);
    setError("");
    setEditErrors({});

    try {
      setSession(
        await classSessions.update(uuid, {
          title: edit.title,
          seats_total: Number(edit.seats),
        }),
      );
    } catch (err: unknown) {
      setEditErrors(fieldErrors(err));
      setError(userMessage(err));
    } finally {
      setSaving(false);
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
        <div className="mt-2">
          <PageHeader Icon={SessionsIcon} title={session.title} />
        </div>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر الإجراء">
          {error}
        </Alert>
      )}

      <Card>
        <div className="mb-4 flex flex-wrap items-center gap-2">
          {/* A closed room outranks a `live` status: the close job only runs at
              the scheduled end, so a lesson the teacher ended stays `live` for a
              while and was badged «جارية» after everyone had left. */}
          <Badge
            tone={
              session.status === "cancelled"
                ? "danger"
                : session.status === "live" && session.room_closed
                  ? "neutral"
                  : "info"
            }
          >
            {session.status === "live" && session.room_closed
              ? "انتهى البثّ"
              : session.status_label}
          </Badge>
          <Badge tone="neutral">{session.type_label}</Badge>
          <SeatBadge seats={session.seats} />
        </div>

        <p className="mb-1 text-sm text-ink-muted">
          {formatSessionTime(session.starts_at, session.timezone)}
        </p>
        <p className="mb-4 text-sm text-ink-muted">
          المدة <bdi>{session.duration_minutes}</bdi> دقيقة · المنطقة الزمنية{" "}
          <bdi>{timezoneLabel(session.timezone)}</bdi>
        </p>

        <div className="flex flex-wrap gap-3">
          {/* The room is reachable from here and from the student's card — there
              is no nav entry for it, because a room without a session is not a
              place. And a closed room is not one either: the door was still
              offered after the broadcast ended, and answered «تعذّر الدخول». */}
          {!session.room_closed && !ROOMLESS.has(session.status) && (
            <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>
          )}

          {session.status === "scheduled" && (
            <Button onClick={() => setAskingCancel(true)} variant="danger">
              إلغاء الحصة
            </Button>
          )}
        </div>

        {/* A window, not one press: a cancel cannot be taken back and tells
            every seat holder at once. Asked while nothing else is happening,
            so a `Modal` rather than `ConfirmButton` (CLAUDE.md). */}
        <Modal
          open={askingCancel}
          title="إلغاء الحصة"
          message="لا يمكن التراجع عن الإلغاء، وسيصل إشعار به إلى كل من حجز مقعداً في هذه الحصة."
          confirmLabel="ألغِ الحصة"
          tone="danger"
          busy={cancelling}
          onConfirm={() => void cancel()}
          onCancel={() => setAskingCancel(false)}
        />
      </Card>

      {session.status === "scheduled" && (
        <Card>
          <div className="mb-4">
            <SectionHeading
              id="edit-session"
              Icon={SettingsIcon}
              title="تعديل الحصة"
              description={
                <>
                  نوع الحصة لا يظهر هنا: لا يمكن تغييره بعد أول حجز.
                </>
              }
            />
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <TextField
              id="edit_title"
              label="عنوان الحصة"
              value={edit.title}
              onChange={(title) => setEdit({ ...edit, title })}
              error={editErrors.title}
            />
            <NumberField
              id="edit_seats"
              label="عدد المقاعد"
              value={edit.seats}
              onChange={(seats) => setEdit({ ...edit, seats })}
              error={editErrors.seats_total}
            />
          </div>

          <div className="mt-4">
            <Button onClick={save} loading={saving} variant="secondary">
              حفظ التعديل
            </Button>
          </div>
        </Card>
      )}

      <Card>
        <div className="mb-3">
          <SectionHeading id="attendance-sheet" Icon={UsersIcon} title="كشف الحضور" />
        </div>
        <AttendanceSheet rows={rows} canOverride onChanged={load} sessionUuid={session.uuid} />
      </Card>

      {session.recording !== null && (
        <Card>
          <div className="mb-3">
            <SectionHeading
              id="session-recording"
              Icon={PlayIcon}
              title="التسجيل"
              description={recordingLabel(session.recording.status)}
            />
          </div>

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
