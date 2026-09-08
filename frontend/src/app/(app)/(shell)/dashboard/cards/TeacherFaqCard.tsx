"use client";

import { useCallback, useEffect, useState } from "react";

import { QuestionIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";
import { profileApi } from "@/lib/profile";
import { DashboardCard } from "./DashboardCard";

/**
 * الأسئلةُ الشائعةُ التي كتبَها المدرّسُ — وبابُ كتابتِها (طلبُ ٢٠٢٦-٠٩-٠٨).
 *
 * ⚠️ **البطاقةُ تعُدُّ ولا تُحرِّر، والمحرِّرُ في «ملفّي» على المرساةِ `#faqs`.**
 * `PUT /teacher/profile` يستبدلُ الملفَّ **كاملاً** في كلِّ حفظ، فمحرِّرٌ ثانٍ هنا
 * يرسلُ الأسئلةَ وحدَها كانَ سيمسحُ الموادَّ والمراحلَ واللغاتِ والنبذةَ في
 * الطلبِ نفسِه — بلا أن يفشلَ شيء. الرابطُ يهبطُ على القسمِ عينِه، فالخطوةُ واحدة.
 *
 * ⚠️ **و`catch(() => null)` يرسمُ لا شيءَ ولا يرسمُ خطأً.** الحسابُ الذي لا ملفَّ
 * له يُجابُ **٤٠٣** — مساعدُ مدرّسٍ جمهورُه `teacher` وليسَ له ملفٌّ عامّ — وشريطُ
 * «تعذّر التحميل» عنده لافتةُ عطلٍ على شاشةٍ تعملُ عندَه تماماً. وهو بعينِه ما
 * تفعلُه شاشةُ «ملفّي» بالقراءةِ نفسِها للسببِ نفسِه.
 */
export function TeacherFaqCard() {
  const [count, setCount] = useState<number | null>(null);
  const [hasProfile, setHasProfile] = useState(true);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);

    profileApi
      .teacher()
      .then((mine) => setCount(mine.faqs.length))
      .catch(() => setHasProfile(false))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  if (!hasProfile) return null;

  return (
    <DashboardCard
      title="الأسئلة الشائعة"
      Icon={QuestionIcon}
      href="/settings/profile#faqs"
      linkLabel="أضف أو عدّل"
      loading={loading}
    >
      <p className="text-3xl font-bold text-ink">
        <bdi>{count === null ? "—" : arabicNumber(count)}</bdi>
      </p>
      <p className="text-sm text-ink-muted">
        {count === 0
          ? "لم تضف أسئلة بعد — أضِف ما يسأله عنك الطلاب وأولياء الأمور، ليظهر في تبويب «أسئلة شائعة» على صفحتك."
          : "سؤالاً يقرأه الطالب وولي أمره على صفحتك قبل الاشتراك."}
      </p>
    </DashboardCard>
  );
}
