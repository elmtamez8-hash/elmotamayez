"use client";

import { useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
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

export function AttendanceSheet({
  rows,
  canOverride,
  onChanged,
}: {
  rows: AttendanceRow[];
  canOverride: boolean;
  onChanged?: () => void;
}) {
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<string | null>(null);

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
          </li>
        ))}
      </ul>
    </div>
  );
}
