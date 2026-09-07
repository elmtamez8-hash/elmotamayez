"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { classSessions, type ClassSession, type SessionBooking } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { formatSessionClock, formatSessionDay } from "@/lib/session-format";
import { DashboardCard } from "./DashboardCard";

/** «يبدأ بعد ساعتين و١٥ دقيقة» — الثواني تُعرَضُ في الدقيقةِ الأخيرةِ وحدَها. */
function untilLabel(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (hours > 0) return `يبدأ بعد ${arabicNumber(hours)} س و${arabicNumber(minutes)} د`;
  if (minutes > 0) return `يبدأ بعد ${arabicNumber(minutes)} دقيقة`;

  return `يبدأ بعد ${arabicNumber(seconds)} ثانية`;
}

/**
 * أقربُ خمسِ حصصٍ للطالب، عبرَ كلِّ مدرّسيه.
 *
 * ⚠️ **مؤقّتٌ واحدٌ للبطاقةِ كلِّها، ولا طلبَ ثانٍ** (`FR-011أ`). كلُّ ما يتحرّكُ
 * على هذه الشاشةِ بمرورِ الوقتِ مشتقٌّ من أرقامٍ وصلَت مع الجلبةِ الأولى: العدُّ
 * التنازليُّ، والانتقالُ إلى «جارية»، وظهورُ دعوةِ الدخول. مؤقّتٌ لكلِّ صفٍّ هو
 * خمسةُ مؤقّتاتٍ تفعلُ عملَ واحد.
 *
 * ⚠️ **ولا شيءَ منها يُشتَقُّ من ساعةِ المتصفّح.** الخادمُ يُرسِلُ
 * `seconds_until_start` و`seconds_until_join_open`، وهذه تُنقِصُهما لا غير: نافذةُ
 * الدخولِ صفٌّ في إعداداتِ المنصّةِ يضبطُه المشغّل، وجهازٌ ساعتُه خطأٌ بساعةٍ
 * كاملةٍ يعرضُ عدّاً تنازليّاً إلى لحظةٍ لا وجودَ لها (SC-016).
 *
 * ⚠️ و`room_closed` يتقدّمُ على الحالةِ في الشارةِ وفي الزرِّ معاً: مدرّسٌ أنهى
 * البثَّ مبكّراً يترُكُ الحصّةَ `live` وغرفتَها محذوفة، فالشاشةُ كانت تشيرُ إلى
 * «جارية» وتعرضُ زرّاً يُجيبُ «تعذّر الدخول».
 */
export function UpcomingSessionsCard() {
  const [bookings, setBookings] = useState<SessionBooking[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    classSessions
      .schedule()
      .then((result) => setBookings(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const rows = bookings
    .flatMap((booking) =>
      booking.session === undefined ? [] : [{ uuid: booking.uuid, session: booking.session }],
    )
    .slice(0, 5);

  const tick = useSessionTick(rows.map((row) => row.session));

  return (
    <DashboardCard
      title="حصصك القادمة"
      href="/schedule"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">
            لا حصص محجوزة.{" "}
            <Link
              href="/teachers"
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              تصفّح المدرّسين
            </Link>
          </p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {rows.map((row) => (
          <SessionRow key={row.uuid} session={row.session} tick={tick} />
        ))}
      </ul>
    </DashboardCard>
  );
}

export function SessionRow({ session, tick }: { session: ClassSession; tick: number }) {
  const untilStart = Math.max(0, session.seconds_until_start - tick);
  const untilOpen =
    session.seconds_until_join_open === null
      ? null
      : Math.max(0, session.seconds_until_join_open - tick);

  // البابُ: جوابُ الخادمِ عندَ الجلب، أو عدُّه هو بلغَ الصفر — ولا ثالثَ لهما.
  const open = (session.join_open || untilOpen === 0) && !session.room_closed;
  const state = session.room_closed
    ? "انتهت"
    : untilStart === 0
      ? "جارية"
      : session.status_label;

  return (
    <li className="rounded-lg border border-line p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="text-sm font-medium text-ink">{session.title}</p>
        <span className="shrink-0 text-xs text-ink-muted">{state}</span>
      </div>

      <p className="text-xs text-ink-muted">
        {formatSessionDay(session.starts_at, session.timezone)} ·{" "}
        {formatSessionClock(session.starts_at, session.timezone)}
        {session.course === undefined || session.course === null
          ? ""
          : ` · ${session.course.title}`}
        {session.teacher_name ? ` · ${session.teacher_name}` : ""}
      </p>

      {!session.room_closed && untilStart > 0 && (
        <p className="mt-1 text-xs text-ink-muted">{untilLabel(untilStart)}</p>
      )}

      {open && (
        <Link
          href={`/sessions/${session.uuid}/room`}
          className="mt-2 inline-block rounded text-sm font-medium text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          دخول الغرفة
        </Link>
      )}
    </li>
  );
}

/**
 * ثانيةٌ واحدةٌ تُنقَصُ من أرقامٍ وصلَت مع الجلبة، لبطاقةٍ كاملةٍ لا لكلِّ صفّ.
 *
 * ⚠️ **مؤقّتٌ واحدٌ، ويتوقّفُ حينَ لا يبقى ما يتحرّك**: كلُّ حصّةٍ بدأت وكلُّ
 * بابٍ حُسِمَ أمرُه. مؤقّتٌ لكلِّ صفٍّ هو خمسةُ مؤقّتاتٍ تفعلُ عملَ واحد، ومؤقّتٌ
 * يدورُ إلى الأبدِ على لوحةٍ متروكةٍ مفتوحةٍ عملٌ بلا أثرٍ على الشاشة.
 *
 * وهو مُصدَّرٌ لأنّ بطاقةَ المدرّسِ تعرضُ الصفوفَ نفسَها: عدّانِ تنازليّانِ
 * مكتوبانِ مرّتَينِ هما جوابانِ يفترقانِ عندَ أوّلِ إصلاح.
 */
export function useSessionTick(sessions: ClassSession[]): number {
  const [tick, setTick] = useState(0);

  /*
   | ⚠️ يُصفَّرُ عندَ كلِّ جلبةٍ جديدة، وإلّا طُرِحَ عدُّ الجلبةِ السابقةِ من
   | أرقامِ الجديدة: «إعادةُ المحاولة» بعدَ دقيقتَينِ كانت ستفتحُ البابَ قبلَ
   | أوانِه بمئةٍ وعشرينَ ثانية. البصمةُ هي الأرقامُ نفسُها، لا مرجعُ المصفوفةِ
   | الذي يتغيَّرُ في كلِّ تصييرٍ فيُصفِّرُ العدَّ كلَّ ثانية.
   */
  const signature = sessions
    .map((session) => `${session.uuid}:${session.seconds_until_start}`)
    .join("|");

  useEffect(() => setTick(0), [signature]);

  const moving = sessions.some(
    (session) =>
      session.seconds_until_start - tick > 0 ||
      (session.seconds_until_join_open ?? 0) - tick > 0,
  );

  useEffect(() => {
    if (!moving) return;

    const timer = setInterval(() => setTick((value) => value + 1), 1000);

    return () => clearInterval(timer);
  }, [moving]);

  return tick;
}
