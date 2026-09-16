"use client";

import { useCallback, useEffect, useState } from "react";

import { QuestionIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";
import { useAuth } from "@/lib/auth-context";
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
 * ⚠️ **و`catch` يرسمُ لا شيءَ ولا يرسمُ خطأً.** الحسابُ الذي لا ملفَّ له يُجابُ
 * **٤٠٣** — مساعدُ مدرّسٍ جمهورُه `teacher` وليسَ له ملفٌّ عامّ — وشريطُ «تعذّر
 * التحميل» عنده لافتةُ عطلٍ على شاشةٍ تعملُ عندَه تماماً. وهو بعينِه ما تفعلُه
 * شاشةُ «ملفّي» بالقراءةِ نفسِها للسببِ نفسِه.
 *
 * ⚠️ **والسؤالُ صارَ يُسأَلُ قبلَ الطلبِ لا بعدَه.** المسلكُ لم يتغيّرْ — لا بطاقةَ
 * لمن لا ملفَّ له — لكنَّ الطريقَ إليه كانَ ٤٠٣ في سِجِلِّ الخادمِ على أوّلِ شاشةٍ
 * بعدَ الدخول، يُقرَأُ عطباً وهو حارسٌ يعملُ. و`teacher_profile_uuid` على
 * `/auth/me` يُجيبُ السؤالَ نفسَه بلا طلب، وهو التهجئةُ التي تقرأُها القائمةُ
 * الجانبيّةُ لكشفِ التسوية. **والرفضُ يبقى مُلتقَطاً**: الحقلُ يقولُ إنَّ صفّاً
 * موجودٌ لا إنَّ القراءةَ ستنجح.
 */
export function TeacherFaqCard() {
  const { user } = useAuth();
  const [count, setCount] = useState<number | null>(null);
  const [refused, setRefused] = useState(false);
  const [loading, setLoading] = useState(true);

  /*
   | ⚠️ مُشتقٌّ في كلِّ تصيير، لا حالةٌ مبذورةٌ منه مرّةً. `user` فارغٌ في أوّلِ
   | رسمٍ حتّى عندَ المدرّسِ الكامل — المزوِّدُ يُبادِلُ الرمزَ بملفِّ الحسابِ بعدَ
   | التركيب — فبذرُ `useState` منه يُجمِّدُ الجوابَ على «لا ملفَّ له» ويُخفي
   | البطاقةَ عن كلِّ مدرّسٍ في المنتَج.
   */
  const hasProfile = user?.teacher_profile_uuid != null;

  const load = useCallback(() => {
    if (!hasProfile) return;

    setLoading(true);

    profileApi
      .teacher()
      .then((mine) => setCount(mine.faqs.length))
      .catch(() => setRefused(true))
      .finally(() => setLoading(false));
  }, [hasProfile]);

  useEffect(load, [load]);

  if (!hasProfile || refused) return null;

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
