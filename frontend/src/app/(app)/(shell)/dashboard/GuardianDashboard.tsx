"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type ReactNode } from "react";

import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { family, GUARDIAN_PERMISSIONS, type GuardianRelation } from "@/lib/notifications";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { AttendanceChartCard } from "./cards/AttendanceChartCard";
import { ChildAttendanceCard } from "./cards/ChildAttendanceCard";
import { ChildBalanceCard } from "./cards/ChildBalanceCard";
import { ChildReportCardCard } from "./cards/ChildReportCardCard";
import { ChildScheduleCard } from "./cards/ChildScheduleCard";
import { ChildrenComparisonCard } from "./cards/ChildrenComparisonCard";
import { ChildSwitcher, selectableChildren } from "./cards/ChildSwitcher";
import { PermissionMissingNote } from "./cards/DashboardCard";

/**
 * ما تراهُ أمُّ كريمٍ حينَ تفتحُ اللوحة (٠٢٩ · `US3`).
 *
 * ⚠️ **البطاقةُ بلا إذنٍ لا تُعرَضُ فارغةً بل لا تُعرَضُ أصلاً.** جدولٌ فارغٌ
 * تحتَ «حصص ابنك» جملةٌ كاذبةٌ عن ابنٍ عندَه حصّةٌ غداً — سببُها إذنٌ لم يُمنَحْ
 * لا غيابُ حصّة — وقارئٌ يرى الفراغَ يستنتِجُ أنّ ابنَه لا يدرس. فمكانَها سطرٌ
 * يسمّي الإذنَ ويحملُ الطريقَ إليه في خطوةٍ واحدة (`FR-014`).
 *
 * ⚠️ والأذونُ تُقرَأُ من **الرابطِ نفسِه** لا من قائمةٍ ثانيةٍ هنا: هو ما يقرؤُه
 * الخادمُ في `GuardianDirectory::childrenOf()`، وتهجئةٌ ثانيةٌ للسؤالِ تضعُ
 * جواباً على الشاشةِ وجواباً آخرَ عندَ الباب — وهو الخطأُ الذي دفعَ ثمنَه
 * `BookingEligibility` و`ListLeaderboardScopes` في هذا المستودعِ من قبل.
 *
 * ⚠️ وقراءةُ الروابطِ هي القراءةُ الجذرُ لا بطاقةً: بلا ابنٍ لا سؤالَ يُسأَل.
 * ولذلك هي وحدَها التي تُسقِطُ الشاشة، وكلُّ بطاقةٍ بعدَها تفشلُ وحدَها.
 */
export function GuardianDashboard() {
  const { user } = useAuth();
  const [relations, setRelations] = useState<GuardianRelation[]>([]);
  const [selected, setSelected] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    family
      .list()
      .then((result) => setRelations(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const children = selectableChildren(relations);
  const current = children.find((child) => child.student_uuid === selected) ?? children[0];

  // أوّلُ الأبناءِ أبجديّاً هو المعروض، ولا يُحفَظُ الاختيارُ بينَ الزيارات.
  useEffect(() => {
    if (current !== undefined && current.student_uuid !== selected) {
      setSelected(current.student_uuid as string);
    }
  }, [current, selected]);

  if (loading) return <RowsSkeleton />;
  if (error !== null) return <ErrorState title={error} onRetry={load} />;

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold text-ink">أهلاً، {user?.first_name}</h2>
        <p className="text-ink-muted">هذه متابعة أبنائك — كلٌّ بحسب الإذن الممنوح لك.</p>
      </div>

      {children.length === 0 ? (
        <NoLinkedChild />
      ) : (
        <>
          {/*
            ⚠️ المقارنةُ **فوقَ** المُبدِّل، لا تحتَه. هي الجوابُ عن «كيفَ حالُ
            أبنائي؟» وهو السؤالُ الذي يفتحُ به وليُّ الأمرِ اللوحةَ أصلاً؛ ووضعُها
            تحتَ بطاقاتِ ابنٍ بعينِه يجعلُها ذيلاً لسؤالٍ آخر. ولا تظهرُ لابنٍ
            واحد.
          */}
          <ChildrenComparisonCard options={children} />
          <ChildSwitcher options={children} value={selected} onChange={setSelected} />
          <ChildCards child={current as GuardianRelation} />
        </>
      )}
    </div>
  );
}

/**
 * وليُّ أمرٍ لا ابنَ **مرتبطاً بحسابٍ** له (`FR-014`).
 *
 * ⚠️ لا أربعُ بطاقاتٍ فارغة. أربعةُ صناديقَ تقولُ «لا بيانات» تصفُ عطلاً، والحالُ
 * أنّ الخطوةَ التاليةَ واحدةٌ ومعروفةٌ — ورابطٌ ابنُه بالاسمِ وحدَه لا معرَّفَ
 * له، فلا سؤالَ يُطرَحُ على الخادمِ عنه أصلاً.
 */
function NoLinkedChild() {
  return (
    <section className="rounded-xl border border-dashed border-line p-6">
      <p className="text-sm text-ink-muted">
        لا يوجد ابن مرتبط بحساب على المنصّة بعد. تظهر بيانات الابن هنا بعد ربط حسابه واعتماد
        الربط.{" "}
        <Link
          href="/family"
          className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          إدارة المرتبطين
        </Link>
      </p>
    </section>
  );
}

/** التسميةُ من `GUARDIAN_PERMISSIONS` لا من نصٍّ ثانٍ يشيخُ عندَ أوّلِ إعادةِ صياغة. */
function permissionLabel(key: string): string {
  return GUARDIAN_PERMISSIONS.find((permission) => permission.key === key)?.label ?? key;
}

function ChildCards({ child }: { child: GuardianRelation }) {
  const studentUuid = child.student_uuid as string;
  const studentName = child.student_name;
  const granted = new Set(child.permissions.map((permission) => permission.key));

  const cards: Array<{ key: string; card: ReactNode }> = [
    { key: "schedule", card: <ChildScheduleCard studentUuid={studentUuid} studentName={studentName} /> },
    {
      /*
      | ⚠️ **البطاقةُ ورسمُها في خانةٍ واحدةٍ وتحتَ الإذنِ نفسِه، عمداً.** ثلاثةُ
      | أسبابٍ تجتمع: إذنٌ واحدٌ يعني **سطرَ «غير ممنوح» واحداً** لا سطرَينِ
      | متطابقَينِ فوقَ بعضِهما؛ والاثنانِ يُصيَّرانِ في التمريرةِ نفسِها فيقتسمانِ
      | الطلبَ الواحدَ الذي يحرسُه `sharedRead` — بطاقةٌ تُركَّبُ بعدَ استقرارِ
      | الوعدِ تُطلِقُ طلبَها هي؛ ورقمانِ عن الطفلِ نفسِه من ردَّينِ يتناقضانِ
      | بلا خطأ.
      */
      key: "attendance",
      card: (
        <div className="space-y-6">
          <ChildAttendanceCard studentUuid={studentUuid} studentName={studentName} />
          <AttendanceChartCard studentUuid={studentUuid} studentName={studentName} />
        </div>
      ),
    },
    { key: "payments", card: <ChildBalanceCard studentUuid={studentUuid} studentName={studentName} /> },
    { key: "results", card: <ChildReportCardCard studentUuid={studentUuid} studentName={studentName} /> },
  ];

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {cards.map((entry) => (
        // ⚠️ المفتاحُ يحملُ معرَّفَ الابنِ كذلك: بدونِه يُعيدُ React استعمالَ
        // المكوِّنِ نفسِه عبرَ التبديل، وتبقى صفوفُ ابنٍ معروضةً تحتَ اسمِ آخرَ
        // حتّى ينتهيَ الجلبُ الجديد.
        <div key={`${studentUuid}:${entry.key}`}>
          {granted.has(entry.key) ? (
            entry.card
          ) : (
            <PermissionMissingNote label={permissionLabel(entry.key)} />
          )}
        </div>
      ))}
    </div>
  );
}
