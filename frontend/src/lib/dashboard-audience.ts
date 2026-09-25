import { teachesOnPlatform } from "@/lib/teaches-on-platform";
import type { User } from "@/lib/types";

/**
 * أيُّ لوحةٍ تُصيَّرُ لهذا الحساب (٠٢٩ · `FR-001`).
 *
 * ⚠️ `isLearner()` أدناه **مشتقّةٌ** من هذه لا تهجئةٌ بجوارِها: تسألُ «هل
 * تتعلّمُ هنا؟» وتجيبُ بنعم عن الطالبِ **وعن وليِّ الأمرِ معاً**، وهذه تسألُ
 * «أيُّ تخطيطٍ يخصُّك؟» وتفرّقُ بينَ الاثنَين. كانتا قاعدتَينِ مختلفتَينِ لحسابٍ
 * بلا دَور، فافترقَ الشريطُ الجانبيُّ عن صفحةِ الهبوطِ عندَ الشخصِ نفسِه.
 *
 * ⚠️ `parent` هو ما تحملُه الحمولةُ، و`guardian` ما تسمّيه الشاشة. القيمةُ في
 * `platform_role` مكتوبةٌ في الخادمِ منذُ ٠٠١؛ وترجمتُها هنا مرّةً واحدةً أرخصُ
 * من مقارنةٍ بـ`"parent"` منثورةٍ في خمسِ بطاقات.
 *
 * والدورُ الفارغُ ليس حالةً نادرة: هو صاحبُ الأكاديميّةِ الذي سجّلَ عبرَ مسارِ
 * المؤسّسات (`RegisterAccount`: «NULL IS THE CORRECT ROLE FOR AN ACADEMY
 * FOUNDER») وهو موظّفُ المنصّة. فالحكمُ عليهما بما يملكانِ لا بما لا يحملان:
 * أيُّ صلاحيّةِ مساحةِ عملٍ ⇒ التخطيطُ الإداريّ. ومديرُ المنصّةِ يمرُّ من هنا
 * بلا سطرٍ خاصٍّ به — `Gate::before` يمرّرُه فوقَ كلِّ صلاحيّة، فحمولتُه تحملُها
 * كلَّها.
 */
export type DashboardAudience = "student" | "teacher" | "guardian";

/**
 * ⛔ THE ONE CLASSIFIER. Every «which side is this account» question in
 * `frontend/src` derives from this function — `isLearner()`, `homePathFor()`,
 * `panelPathFor()`, the sidebar, `/dashboard` and the guardian-only screens — so
 * the menu, the landing page and the page body cannot disagree about a person.
 *
 * The order is load-bearing:
 *
 * 1. `parent` ⇒ guardian, whatever else they are (a guardian who also teaches
 *    stays on the learner side, as `homePathFor` always kept them).
 * 2. TEACHING ⇒ teacher, read from the pivot role (`teachesOnPlatform`), before
 *    the `student` role — the same predicate as the server's purchase doors.
 * 3. The declared roles.
 * 4. A null role (founder, officer, or a student a teacher or seeder created):
 *    staff if they teach or hold a platform flag, a student otherwise.
 *
 * ⚠️ STEP 4 USED TO READ `permissions.length`, AND THAT WAS A PERSON. A student
 * a teacher added to a workspace carries a `student` pivot row and the `student`
 * role's ten permissions, so a null-role student with `last_workspace_id` set
 * got the TEACHER layout and lost every `audience: ["student"]` menu item —
 * while `panelPathFor()` sent the same person to `/enrollments`. `workspaces`
 * excludes the `student` pivot role on the server (`UserResource::workplaces()`),
 * so it is the question «do you work here», and permissions never were.
 */
export function dashboardAudience(user: User | null): DashboardAudience {
  if (user?.platform_role === "parent") return "guardian";
  if (teachesOnPlatform(user)) return "teacher";
  if (user?.platform_role === "student") return "student";
  if (user?.platform_role === "teacher") return "teacher";

  return user?.is_super_admin === true || user?.may_access_admin_panel === true
    ? "teacher"
    : "student";
}

/**
 * «Do you learn here?» — a student or a guardian (who reads their child's
 * screens through the learner side). Derived from {@link dashboardAudience}, never
 * spelled beside it; `null` (a visitor, or a session still restoring) is not a
 * learner.
 */
export function isLearner(user: User | null): boolean {
  return user !== null && dashboardAudience(user) !== "teacher";
}
