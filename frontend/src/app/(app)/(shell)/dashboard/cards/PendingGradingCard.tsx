"use client";

import { useAuth } from "@/lib/auth-context";
import { grading } from "@/lib/grading";
import { can, P } from "@/lib/permissions";
import { GradingIcon } from "@/components/icons";
import { CountCard } from "./CountCard";

/**
 * كم ورقةً تنتظرُ يدَ هذا المصحِّح (٠٢٩ · `FR-007`).
 *
 * ⚠️ **الرقمُ من `meta.total`، لا من طولِ الصفحة**: الطابورُ يُقسَّمُ، فعدُّ
 * الصفوفِ يتوقّفُ عندَ حدِّ الصفحةِ ويُطمئنُ مصحِّحاً عندَه ستّونَ ورقة.
 *
 * ⚠️ والحراسةُ `grading.perform` — الصلاحيّةُ التي تحرسُ `‎/manage/grading` في
 * القائمةِ الجانبيّةِ نفسِها، لا الدَّور. مساعدٌ منحَه صاحبُ المساحةِ التصحيحَ
 * يرى البطاقةَ، وآخرُ لا يملكُه لا يراها ولو كان مساعداً مثلَه.
 */
/*
 * ⚠️ **خمسةٌ لا عشرون ولا واحد، والرقمُ مقيسٌ لا مختار.** البطاقةُ تعرضُ
 * `meta.total` وحدَه، فجلبُ عشرينَ ورقةً كاملةً لأجلِ رقمٍ واحدٍ تسعةَ عشرَ صفّاً
 * لا يقرؤُها أحد. و`per_page=1` كذبةٌ عن العقد: الخادمُ يحصرُه في
 * `min(50, max(5, …))` فيصيرُ **خمسةً** مهما طلبتَ أقلَّ — وشريطُ التصحيحِ
 * الجانبيُّ في الغلافِ يطلبُ `1` ويأخذُ خمسةً منذُ كُتِب.
 */
const countPending = () => grading.queue(1, 5).then((page) => page.meta?.total ?? 0);

export function PendingGradingCard() {
  const { user } = useAuth();

  return (
    <CountCard
      title="بانتظار التصحيح"
      Icon={GradingIcon}
      href="/manage/grading"
      linkLabel="لوحة التصحيح"
      label="ورقة تنتظر تصحيحك"
      granted={can(user, P.gradingPerform)}
      empty="لا أوراق تنتظر التصحيح."
      load={countPending}
    />
  );
}
