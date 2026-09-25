"use client";

import { useEffect } from "react";
import { openAdminPanel } from "@/lib/admin-panel";

import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
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

  /*
   | ⛔ **مالكُ المنصّةِ لوحتُه `‎/admin`، وكانَ يهبطُ هنا على لوحةِ مدرّسٍ ليست
   | لوحتَه.** قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦: حسابُ المالكِ يملكُ صفرَ مساحاتِ
   | عملٍ ولا ملفَّ تدريس، فكلُّ بطاقةٍ هنا إمّا فارغةٌ أو عن شخصٍ آخر.
   |
   | ⚠️ **والشرطانِ معاً لا أحدُهما.** «يدخُلُ اللوحة» وحدَه يُحوِّلُ مديرَ منصّةٍ
   | يُدرِّسُ أيضاً بعيداً عن صفوفِه، و«بلا مساحةِ عمل» وحدَه يُحوِّلُ طالباً —
   | ولا يصلُ هنا أصلاً، فالجمهورُ فُرِزَ قبلَه، لكنَّ شرطاً يعتمدُ على ترتيبِ
   | سطرٍ فوقَه شرطٌ ينكسرُ عندَ أوّلِ إعادةِ ترتيب.
   |
   | ⚠️ **و`window.location` لا `router`**: اللوحةُ تطبيقُ Laravel على المضيفِ
   | نفسِه، ولا يعرفُها موجِّهُ Next. و`replace` لا `assign` حتّى لا يردَّ زرُّ
   | الرجوعِ القارئَ إلى صفحةٍ تُحوِّلُه من جديد.
   |
   | ⚠️ **وليسَ باباً في اتّجاهٍ واحد**: لوحةُ Filament تحملُ «الصفحة الرئيسية»
   | بـ`sort(-1)`، والقائمةُ الجانبيّةُ هنا تحملُ «لوحة المنصّة» — والرابطانِ
   | معاً هما ما يجعلُ هذا تحويلاً لا حبساً.
   */
  const toPanel = user?.may_access_admin_panel === true && !teachesOnPlatform(user);

  useEffect(() => {
    if (!toPanel) return;

    /*
      ⚠️ **جسرٌ لا رابط.** `window.location.replace("/admin")` كانَ يهبطُ على
      شاشةِ دخولٍ ثانيةٍ لمسؤولٍ سجّلَ دخولَه توّاً: الرمزُ في `localStorage` لا
      يسافرُ مع طلبِ صفحة. والنداءُ يطلبُ تذكرةً بالمفتاحِ الذي في اليدِ ثمّ
      يذهبُ إلى العنوانِ الذي يردُّه الخادم.

      ⚠️ وعندَ الفشلِ **لا نُبقي الشاشةَ فارغةً**: `toPanel` يُرجِعُ `null` أدناه،
      فسقوطُ النداءِ بلا بديلٍ صفحةٌ بيضاءُ بلا سببٍ ظاهر. العنوانُ المباشرُ هو
      الارتدادُ — يطلبُ كلمةَ السرِّ مرّةً ثانيةً، وهو ما كانَ يحدثُ دائماً قبلَ
      هذا، لا انحداراً جديداً.
    */
    void openAdminPanel().catch(() => window.location.replace("/admin"));
  }, [toPanel]);

  if (toPanel) return null;

  const audience = dashboardAudience(user);

  if (audience === "guardian") return <GuardianDashboard />;
  if (audience === "student") return <StudentDashboard />;

  // والمدرّسُ هو الافتراض: حسابٌ بلا دَورٍ مسجَّلٍ يحملُ صلاحيّةَ مساحةِ عملٍ
  // يقرؤُه `dashboardAudience()` مدرّساً، وهو ما تقولُه المواصفةُ في «الحالاتُ
  // الحدّيّة». و`LegacyDashboard.tsx` حُذِفَ هنا: لم يبقَ له قارئ.
  return <TeacherDashboard />;
}
