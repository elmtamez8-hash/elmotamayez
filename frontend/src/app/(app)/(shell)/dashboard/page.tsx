"use client";

import { useAuth } from "@/lib/auth-context";
import { dashboardAudience } from "@/lib/dashboard-audience";
import { GuardianDashboard } from "./GuardianDashboard";
import { StudentDashboard } from "./StudentDashboard";
import { TeacherDashboard } from "./TeacherDashboard";

/**
 * `/dashboard` — مُوجِّهٌ لا شاشة (٠٢٩ · `FR-001`).
 *
 * ⚠️ **ولا قراءةَ بيانٍ واحدةٍ تبقى هنا.** هذا الملفُّ حملَ ثلاثَ قراءاتٍ في
 * `Promise.all` واحد، فرفضُ واحدةٍ منها أسقطَ اللوحةَ كلَّها لكلِّ طالبٍ ووليِّ
 * أمرٍ على المنصّة. والقاعدةُ التي تمنعُ عودةَ ذلك ليست انضباطاً بل بناءً: ما
 * دامَ المُوجِّهُ لا يقرأُ شيئاً، لا يوجدُ فيه مكانٌ يقعُ فيه.
 *
 * ⚠️ ولا صلاحيّةَ على هذا العنوانِ في القائمةِ الجانبيّة — يصلُه الطالبُ ووليُّ
 * الأمرِ والمدرّسُ والمساعد. فكلُّ قراءةٍ تحتَه يجبُ أن تكونَ قراءةً يملكُها
 * جمهورُ الشاشةِ كلُّه.
 */
export default function DashboardPage() {
  const { user } = useAuth();

  const audience = dashboardAudience(user);

  if (audience === "guardian") return <GuardianDashboard />;
  if (audience === "student") return <StudentDashboard />;

  // والمدرّسُ هو الافتراض: حسابٌ بلا دَورٍ مسجَّلٍ يحملُ صلاحيّةَ مساحةِ عملٍ
  // يقرؤُه `dashboardAudience()` مدرّساً، وهو ما تقولُه المواصفةُ في «الحالاتُ
  // الحدّيّة». و`LegacyDashboard.tsx` حُذِفَ هنا: لم يبقَ له قارئ.
  return <TeacherDashboard />;
}
