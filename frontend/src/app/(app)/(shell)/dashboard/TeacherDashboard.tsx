"use client";

import { useAuth } from "@/lib/auth-context";
import { LatestNotificationsCard } from "./cards/LatestNotificationsCard";
import { PendingGradingCard } from "./cards/PendingGradingCard";
import { PrivateRequestsCard } from "./cards/PrivateRequestsCard";
import { QuickLinksCard } from "./cards/QuickLinksCard";
import { TeacherSessionsCard } from "./cards/TeacherSessionsCard";
import { WeekSessionsChartCard } from "./cards/WeekSessionsChartCard";
import { WithheldStudentsCard } from "./cards/WithheldStudentsCard";

/**
 * ما يراهُ المدرّسُ حينَ يفتحُ اللوحة (٠٢٩ · `US2`).
 *
 * ⚠️ **كلُّ حراسةٍ بثابتِ `Permissions` عبرَ `can()`، لا باسمِ دَورٍ ولا بنصٍّ
 * حرفيّ** (`FR-003`). الحراسةُ داخلَ كلِّ بطاقةٍ لا هنا: القاعدةُ هي التي تُقرَأُ
 * بجوارِ القراءةِ التي تحرسُها، وقائمةُ شروطٍ في هذا الملفّ تُصبِحُ جواباً ثانياً
 * يفترقُ عن الأوّلِ عندَ أوّلِ صلاحيّةٍ تتحرّك — وهو العطبُ الذي دفعَ ثمنَه هذا
 * المستودعُ في `BookingEligibility` و`ListLeaderboardScopes`.
 *
 * والنتيجةُ أنّ **مساعدَ المدرّسِ ممنوعٌ عندَ الفحصِ لا عندَ اسمِ الدَّور**: دَورٌ
 * مخصَّصٌ اسمُه أيُّ شيءٍ ما زالَ محجوباً، ومساعدٌ منحَه صاحبُ المساحةِ التصحيحَ
 * يرى بطاقتَه — وهو ما يقصدُه `FR-031` من أنّ المصفوفةَ تبذُرُ افتراضاً لا حكماً.
 *
 * ⚠️ ولا `Promise.all` ولا قراءةَ واحدةٍ في هذا الملفّ (`FR-013`): كلُّ بطاقةٍ
 * تجلبُ بنفسِها وتفشلُ وحدَها. العطلُ المسجَّلُ في صدرِ هذه الشاشةِ كان ثلاثَ
 * قراءاتٍ في وعدٍ واحد.
 *
 * ⚠️ و**لا بطاقةَ طلبات** هنا وإن ذكرَها سيناريو ٤ في المواصفة: `FR-007` لا
 * يُدرِجُها في تخطيطِ المدرّس، وعقدُ المرحلةِ يضعُ قراءةَ الطلباتِ على الطالبِ
 * ووليِّ الأمرِ وحدَهما. وبطاقةٌ هنا كانت سترتبطُ بشاشةِ مشترٍ.
 */
export function TeacherDashboard() {
  const { user } = useAuth();

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold text-ink">أهلاً، {user?.first_name}</h2>
        <p className="text-ink-muted">هذه نظرة عامة على صفّك اليوم.</p>
      </div>

      {/* الأرقامُ الثلاثةُ أوّلاً: ما ينتظرُ قراراً منه، لا ما أنجزَه. وكلٌّ منها
          تُخفي نفسَها حينَ لا يملكُ القارئُ صلاحيّتَها. */}
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
        <PendingGradingCard />
        <PrivateRequestsCard />
        <WithheldStudentsCard />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <TeacherSessionsCard />
        {/* الرسمُ بجوارِ الجدولِ ومن ردِّه نفسِه: الجدولُ أقربُ خمسٍ والرسمُ شكلُ
            الأسبوعِ كلِّه — عيّنةٌ وإجماليٌّ من طلبٍ واحد. */}
        <WeekSessionsChartCard />
        <LatestNotificationsCard />
      </div>

      <QuickLinksCard />
    </div>
  );
}
