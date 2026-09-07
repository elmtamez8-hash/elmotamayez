"use client";

import { useAuth } from "@/lib/auth-context";
import { billing } from "@/lib/billing";
import { can, P } from "@/lib/permissions";
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
      href="/manage/billing/students"
      linkLabel="أرصدة الطلاب"
      label="طالب لا يستطيع الحجز"
      granted={can(user, P.billingBalanceView)}
      empty="لا طالب محجوب."
      load={countWithheldStudents}
    />
  );
}
