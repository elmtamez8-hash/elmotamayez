"use client";

import { useAuth } from "@/lib/auth-context";
import { billing } from "@/lib/billing";
import { can, P } from "@/lib/permissions";
import { CreditsIcon } from "@/components/icons";
import { CountCard } from "./CountCard";

/**
 * كم طالباً محجوبٌ عن الحجزِ في هذه المساحة (٠٢٩ · `FR-007`).
 *
 * ⚠️ **عددُ أشخاصٍ لا عددُ صفوف، ولا مبلغَ في أيِّ اتّجاه.** الحجبُ قائمٌ **لكلِّ
 * كورسٍ وحدَه** فالصفُّ هو (طالب × كورس): طالبٌ محجوبٌ في مادّتَينِ صفّانِ وشخصٌ
 * واحد، وعدُّ الصفوفِ يُبلِّغُ المدرّسَ عن ضعفِ من عندَه. والمبلغُ ممنوعٌ أصلاً —
 * `StudentBalanceAllowlist` يُسقِطُ البناءَ على حقلٍ ماليٍّ في هذه الحمولة.
 *
 * ⚠️ والحراسةُ `billing.balance.view`، وهي **الصلاحيّةُ الماليّةُ الوحيدةُ التي
 * يُفوِّضُها المدرّس**: مساعدٌ لم تُمنَحْ له لا يرى هذه البطاقةَ ولا تُنادى قراءتُها
 * — وهو ما يقيسُه اختبارُ المساعدِ في `page.test.tsx`.
 *
 * ⚠️ **والصلاحيّةُ ليست الشرطَ الوحيد، ومالكُ المنصّةِ هو مَن كشفَ ذلك.**
 * `Gate::before` يمرّرُ مديرَ المنصّةِ فوقَ كلِّ صلاحيّة، فحمولتُه تحملُها كلَّها
 * و`can()` هنا يقولُ نعم — بينما القراءةُ نفسُها ترفضُ بـ٤٠٣ من سطرٍ ثانٍ تماماً:
 * `abort_if($workspaceId === null, 403)`، وتعليقُه يقولُ لماذا («لا جوابَ اسمُه
 * طلّابُ كلِّ المدرّسين، واللوحةُ لوحةُ مدرّسٍ واحد»). فحسابٌ لا يملكُ مساحةَ عملٍ
 * أصلاً — قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦: `workspaces = 0` و`last_workspace_id`
 * فارغ — كانَ يقرأُ «لا تملك صلاحية لهذا الإجراء» على أوّلِ شاشةٍ بعدَ الدخول،
 * وهي **الصلاحيّةُ الوحيدةُ التي يملكُها بلا نزاع**. فالجملةُ كاذبةٌ فوقَ رفضٍ
 * صادق.
 *
 * والشرطُ يُقرَأُ من الحمولةِ ولا يُشتقُّ: `workspaces` على `/auth/me` منذُ ٠٢٥.
 * وهو يفشلُ في الاتّجاهِ الآمن — مساحةٌ في القائمةِ بلا سياقٍ محلولٍ تُبقي الحالَ
 * كما هو، وقائمةٌ فارغةٌ تعني سياقاً فارغاً يقيناً، إذ لا شيءَ آخرَ يُحَلُّ منه.
 */
const countWithheldStudents = () =>
  billing.students().then(
    (result) =>
      new Set(
        (result.data ?? []).filter((row) => row.is_withheld).map((row) => row.student_uuid),
      ).size,
  );

export function WithheldStudentsCard() {
  const { user } = useAuth();

  return (
    <CountCard
      title="طلاب محجوبون"
      Icon={CreditsIcon}
      href="/manage/billing/students"
      linkLabel="أرصدة الطلاب"
      label="طالب لا يستطيع الحجز"
      granted={can(user, P.billingBalanceView) && (user?.workspaces?.length ?? 0) > 0}
      empty="لا طالب محجوب."
      load={countWithheldStudents}
    />
  );
}
