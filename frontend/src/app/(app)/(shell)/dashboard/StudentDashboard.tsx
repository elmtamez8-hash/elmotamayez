"use client";

import { useAuth } from "@/lib/auth-context";
import { CourseBalancesCard } from "./cards/CourseBalancesCard";
import { LatestNotificationsCard } from "./cards/LatestNotificationsCard";
import { LatestOrdersCard } from "./cards/LatestOrdersCard";
import { ProgressChartCard } from "./cards/ProgressChartCard";
import { QuickLinksCard } from "./cards/QuickLinksCard";
import { StatCountsCard } from "./cards/StatCountsCard";
import { UpcomingSessionsCard } from "./cards/UpcomingSessionsCard";

/**
 * ما يراهُ الطالبُ حينَ يفتحُ اللوحة (٠٢٩ · `US1`).
 *
 * ⚠️ **لا وعدَ مجموعاً في هذا الملفّ، ولا قراءةَ واحدة.** كلُّ بطاقةٍ تجلبُ
 * بنفسِها وتملكُ حالاتِها الأربع، وهو التنفيذُ الحرفيُّ لـ`FR-013`: العطلُ
 * المسجَّلُ في صدرِ هذه الشاشةِ كان ثلاثَ قراءاتٍ في `Promise.all` واحد، ورفضُ
 * واحدةٍ منها أسقطَ الشاشةَ كلَّها لكلِّ طالبٍ على المنصّة. ما دامَ لا مكوِّنَ
 * يجمعُ قراءتَين، لا يوجدُ مكانٌ يقعُ فيه ذلك ثانية.
 *
 * ⚠️ وكلُّ قراءةٍ هنا قراءةٌ **يملكُها الطالب**: لا `‎/courses` (فهرسُ التأليف،
 * محروسٌ بصلاحيّةِ مساحةِ عمل)، ولا شاشةَ إدارةٍ في الروابطِ السريعةِ — تلك
 * تُرشَّحُ بـ`allowedNav` قبلَ أن تصلَ إلى هنا.
 */
export function StudentDashboard() {
  const { user } = useAuth();

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold text-ink">أهلاً بعودتك، {user?.first_name}</h2>
        <p className="text-ink-muted">هذه نظرة عامة على دراستك.</p>
      </div>

      <StatCountsCard />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* الحصّةُ القادمةُ أوّلاً: «متى حصّتي؟» هو السؤالُ الذي تُفتَحُ به هذه
            الشاشةُ، والرصيدُ بعدَه لأنّه ما يمنعُ حجزَ التالية. */}
        <UpcomingSessionsCard />
        <CourseBalancesCard />
        {/* الرسمُ تحتَ رقمِ «كورسات جارية» مباشرةً ومن ردِّه نفسِه: الرقمُ يقولُ
            كم، والأشرطةُ تقولُ أين وصلَ في كلٍّ منها. */}
        <ProgressChartCard />
        <LatestNotificationsCard />
        <LatestOrdersCard />
      </div>

      <QuickLinksCard />
    </div>
  );
}
