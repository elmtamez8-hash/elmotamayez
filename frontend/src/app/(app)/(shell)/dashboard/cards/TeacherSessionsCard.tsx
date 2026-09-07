"use client";

import Link from "next/link";

import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/lib/auth-context";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { can, P } from "@/lib/permissions";
import type { User } from "@/lib/types";
import { SessionsIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import { sharedRead } from "./shared-read";
import { SessionRow, useSessionTick } from "./UpcomingSessionsCard";

/** «اليوم» بتقويمِ الجهاز، بالشكلِ الذي تُرسِلُه شاشةُ «حصصي» نفسُها. */
function today(): string {
  const now = new Date();
  const pad = (value: number) => String(value).padStart(2, "0");

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * جدولُ حصصِ المدرّسِ القادمة (٠٢٩ · `FR-007أ`).
 *
 * ⚠️ **العنوانُ يتبعُ المُرشِّح، والاثنانِ من `teacher_profile_uuid` وحدَه.** من
 * يستضيفُ حصصاً يُقرَأُ له `?teacher={uuid}` تحتَ «حصصي»؛ ومن لا يستضيفُ شيئاً
 * ويملكُ إدارةَ الحصصِ — المساعدُ نموذجاً — يُقرَأُ له بلا مُرشِّحٍ تحتَ عنوانٍ
 * يقولُ إنّها حصصُ مكانِ العمل — بمفردةِ المنتَجِ نفسِها، فـ`SC-002` في مواصفةِ
 * ٠٢٥ يمنعُ «مساحةَ العمل» في أيِّ نصٍّ يقرؤُه مستخدِمٌ ويحرسُها
 * `workspace-vocabulary.test.ts`. وخلطُ الاثنَينِ عطلانِ في اتّجاهَين: جدولٌ **فارغٌ**
 * تحتَ «حصصي» لمن لا حصّةَ له أصلاً، وحصّةُ **زميلٍ** تحتَ «حصصي» لو سقطَ
 * المُرشِّح — والثاني لا يُخطئ، فلا أحدَ يراه.
 *
 * ⚠️ ولا بطاقةَ إطلاقاً لمن لا يستضيفُ ولا يُديرُ: `null` تحتَ عنوانٍ ثالثٍ
 * مخترَعٍ هو نفسُ الكذبةِ بصياغةٍ أخرى، و`FR-015` يمنعُ بطاقةً بلا شاشةٍ تصلُ
 * إليها.
 *
 * ⚠️ و`from` **تاريخٌ لا لحظة**: لحظةٌ كاملةٌ تُسقِطُ حصّةً بدأت قبلَ عشرِ دقائقَ
 * وما زالت جارية — وهي أوّلُ ما يبحثُ عنه المدرّسُ حينَ يفتحُ الشاشة. والتهجئةُ
 * هي تهجئةُ `manage/sessions` نفسُها: تاريخانِ لمعنى «اليوم» يفترقانِ عندَ أوّلِ
 * منطقةٍ زمنيّة.
 */
/**
 * من يرى جدولاً أصلاً، وبأيِّ مُرشِّح — سؤالٌ واحدٌ تسألُه البطاقةُ والرسمُ معاً.
 *
 * ⚠️ **تهجئةٌ واحدةٌ لا اثنتان**: الرسمُ يبني أعمدتَه من صفوفِ الجدولِ نفسِها،
 * فشرطانِ مكتوبانِ مرّتَينِ هما جوابانِ يفترقانِ عندَ أوّلِ إصلاح — وهو العطبُ
 * الذي دفعَ ثمنَه هذا المستودعُ في `BookingEligibility` و`ListLeaderboardScopes`.
 */
export function teacherSessionsAudience(user: User | null): {
  hostUuid: string | null;
  managesWorkspace: boolean;
} {
  const hostUuid = user?.teacher_profile_uuid ?? null;

  return { hostUuid, managesWorkspace: hostUuid === null && can(user, P.sessionsManage) };
}

/**
 * حصصُ القارئِ القادمة — طلبٌ واحدٌ تقرؤُه البطاقةُ والرسمُ (`FR-018`).
 *
 * ⚠️ **سقفٌ يُوَثَّقُ ولا يُرفَع**: `/class-sessions` تُقسِّمُ بخمسينَ صفّاً ولا
 * تقرأُ `per_page`، فأسبوعٌ فيه أكثرُ من خمسينَ حصّةً يُرسَمُ ناقصاً. الرقمُ
 * مكتوبٌ هنا لأنّ رفعَه يبدأُ بمعامِلِ صفحةٍ في الخادمِ لا برقمٍ في المتصفّح.
 */
export function readTeacherSessions(hostUuid: string | null): Promise<{ data: ClassSession[] }> {
  const params = {
    from: today(),
    order: "asc" as const,
    ...(hostUuid === null ? {} : { teacher: hostUuid }),
  };

  return sharedRead(`class-sessions:${hostUuid ?? "workspace"}`, () => classSessions.list(params));
}

export function TeacherSessionsCard() {
  const { user } = useAuth();
  const { hostUuid, managesWorkspace } = teacherSessionsAudience(user);

  const [rows, setRows] = useState<ClassSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    if (hostUuid === null && !managesWorkspace) return;

    setLoading(true);
    setError(null);

    readTeacherSessions(hostUuid)
      .then((result) => setRows((result.data ?? []).slice(0, 5)))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [hostUuid, managesWorkspace]);

  useEffect(load, [load]);

  const tick = useSessionTick(rows);

  if (hostUuid === null && !managesWorkspace) return null;

  return (
    <DashboardCard
      title={hostUuid === null ? "حصص مكان العمل القادمة" : "حصصي القادمة"}
      Icon={SessionsIcon}
      href="/manage/sessions"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">
            لا حصص قادمة.{" "}
            <Link
              href="/manage/sessions"
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              أنشئ حصّة
            </Link>
          </p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {rows.map((session) => (
          <SessionRow key={session.uuid} session={session} tick={tick} />
        ))}
      </ul>
    </DashboardCard>
  );
}
