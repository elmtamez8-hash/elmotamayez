"use client";

import { useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { attendance, type AttendanceRow } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";

/**
 * The register.
 *
 * `auto_status` is shown next to `status` whenever they differ, because FR-025
 * requires the automatic verdict to survive an override — visibly. A sheet that
 * displays only the final mark hides that a person changed it, and a record
 * which hides having been edited gets trusted more than it has earned.
 */
const STATUS_TONE: Record<string, "success" | "danger" | "warning" | "info"> = {
  present: "success",
  absent: "danger",
  late: "warning",
  excused: "info",
};

/**
 * One student's remark row.
 *
 * Split out so the student's uuid arrives as a plain string prop. Inline, the
 * callbacks needed `row.student!.uuid` — a non-null assertion inside a branch
 * that had already proved it, which is the kind of `!` that survives a refactor
 * after the check around it is gone.
 */
function FeedbackField({
  rowUuid,
  studentUuid,
  value,
  onChange,
  onSave,
  busy,
  saved,
}: {
  rowUuid: string;
  studentUuid: string;
  value: string;
  onChange: (value: string, studentUuid: string) => void;
  onSave: (studentUuid: string) => Promise<void>;
  busy: string | null;
  saved: string | null;
}) {
  return (
    <div className="flex w-full flex-wrap items-end gap-2">
      <div className="min-w-56 grow">
        <TextField
          id={`note-${rowUuid}`}
          label="ملاحظة المدرّس"
          value={value}
          onChange={(next) => onChange(next, studentUuid)}
          maxLength={500}
          placeholder="تصل وليّ الأمر مع تقرير الحصة"
        />
      </div>

      <Button
        size="sm"
        variant="secondary"
        loading={busy === studentUuid}
        onClick={() => void onSave(studentUuid)}
      >
        {saved === studentUuid ? "حُفظت" : "حفظ الملاحظة"}
      </Button>
    </div>
  );
}

export function AttendanceSheet({
  rows,
  canOverride,
  onChanged,
  sessionUuid,
}: {
  rows: AttendanceRow[];
  canOverride: boolean;
  onChanged?: () => void;
  /** Present only where remarks may be written — the teacher's session page. */
  sessionUuid?: string;
}) {
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState<string | null>(null);

  if (rows.length === 0) {
    return (
      <EmptyState
        title="لا كشف حضور بعد"
        description="يُنتَج الكشف تلقائياً عند انتهاء الحصة، ويغطّي كل مقعد محجوز."
      />
    );
  }

  const mark = async (uuid: string, status: string) => {
    setBusy(uuid);
    setError("");

    try {
      await attendance.override(uuid, status, "تحضير يدوي من المدرّس");
      onChanged?.();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(null);
    }
  };

  const saveNote = async (studentUuid: string) => {
    if (sessionUuid === undefined) return;

    setBusy(studentUuid);
    setError("");

    try {
      await attendance.feedback(sessionUuid, [
        { student_uuid: studentUuid, note: notes[studentUuid] ?? "" },
      ]);
      setSaved(studentUuid);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-3">
      {error !== "" && <p className="text-sm text-danger-ink">{error}</p>}

      <ul className="space-y-2">
        {rows.map((row) => (
          <li
            key={row.uuid}
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-medium text-ink">
                {row.student?.name ?? "طالب"}
              </p>
              <p className="text-xs text-ink-muted">
                مدة البقاء <bdi>{Math.floor(row.stay_seconds / 60)}</bdi> دقيقة
                {row.recording_watched_at !== null && " · شاهد التسجيل لاحقاً"}
              </p>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2">
              <Badge tone={STATUS_TONE[row.status] ?? "neutral"}>{row.status_label}</Badge>

              {/* The automatic verdict, whenever a person overrode it. */}
              {row.was_overridden && row.auto_status_label !== null && (
                <Badge tone="neutral">آلياً: {row.auto_status_label}</Badge>
              )}

              {canOverride && row.status !== "present" && (
                <Button
                  size="sm"
                  variant="secondary"
                  loading={busy === row.uuid}
                  onClick={() => void mark(row.uuid, "present")}
                >
                  تحضير يدوي
                </Button>
              )}
            </div>

            {/* The remark that rides along with the report. Optional on purpose:
                the report goes out on the announced delay with attendance alone,
                so an empty box never holds a guardian's message back. */}
            {sessionUuid !== undefined && row.student != null && (
              <FeedbackField
                rowUuid={row.uuid}
                studentUuid={row.student.uuid}
                value={notes[row.student.uuid] ?? ""}
                onChange={(value, uuid) =>
                  setNotes((current) => ({ ...current, [uuid]: value }))
                }
                onSave={saveNote}
                busy={busy}
                saved={saved}
              />
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
