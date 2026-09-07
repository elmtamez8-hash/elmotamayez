"use client";

import { useCallback, useEffect, useState } from "react";

import { classSessions, type ChildSessionBooking } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import type { ChildCardProps } from "./ChildCardProps";
import { sharedRead } from "./shared-read";
import { DashboardCard } from "./DashboardCard";

/**
 * حصصُ الابنِ القادمة (٠٢٩ · `FR-019`).
 *
 * ⚠️ **بلا دعوةِ دخولٍ ولا زرِّ غرفة.** وليُّ الأمرِ لا يدخلُ حصّةَ ابنِه:
 * الخادمُ يرفضُه بلا مقعد، فزرٌّ هنا وعدٌ يُجيبُه `403` — والمَورِدُ الضيِّقُ
 * لا يُرسِلُ `join_open` أصلاً، وهذا هو نصفُ الحراسةِ الآخر.
 */
/** قراءةٌ واحدةٌ في الطيران: جدولُ المقارنةِ يقرأُ المسارَ نفسَه للابنِ المعروض. */
export function readChildSchedule(studentUuid: string) {
  return sharedRead(`child-schedule:${studentUuid}`, () => classSessions.childSchedule(studentUuid));
}

export function ChildScheduleCard({ studentUuid, studentName }: ChildCardProps) {
  const [rows, setRows] = useState<ChildSessionBooking[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    readChildSchedule(studentUuid)
      .then((result) => setRows(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [studentUuid]);

  // تبديلُ الابنِ يُعيدُ الجلبَ **بالبناء**: المعرَّفُ في اعتمادِ الدالّة، فلا
  // يوجدُ مسارٌ يُبقي صفوفَ ابنٍ تحتَ اسمِ آخر.
  useEffect(load, [load]);

  return (
    <DashboardCard
      title={`حصص ${studentName} القادمة`}
      href="/family"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">لا حصص محجوزة قادمة.</p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {rows.slice(0, 5).map((booking) => {
          const session = booking.class_session;
          if (session === null) return null;

          return (
            <li key={booking.uuid} className="rounded-lg border border-line p-3">
              <p className="text-sm font-medium text-ink">{session.title}</p>
              <p className="text-xs text-ink-muted">
                {/* ponytail: بتوقيتِ المتصفّح — المَورِدُ الضيِّقُ لا يُرسِلُ
                    `timezone`. إن اختلفَ توقيتُ الأسرةِ عن جهازِها فالترقيةُ
                    إضافةُ الحقلِ إلى `ChildSessionResource` واستعمالُ
                    `formatSessionTime` كما تفعلُ شاشاتُ الطالب. */}
                {formatDateTime(session.starts_at)}
                {session.course === null ? "" : ` · ${session.course.title}`}
                {session.teacher_name === null ? "" : ` · ${session.teacher_name}`}
              </p>
              <p className="text-xs text-ink-muted">
                {/* الغرفةُ تُغلَقُ قبلَ أن تلحقَ بها الحالة، فهي تتقدَّمُ عليها. */}
                {session.room_closed ? "انتهت" : session.status_label}
              </p>
            </li>
          );
        })}
      </ul>
    </DashboardCard>
  );
}
