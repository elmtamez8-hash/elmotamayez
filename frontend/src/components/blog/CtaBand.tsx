import { Button } from "@/components/ui/Button";
import {
  AcademicCapIcon,
  FamilyIcon,
  UserPlusIcon,
} from "@/components/icons";

/**
 * ثلاثةُ أبوابٍ لثلاثةِ قرّاء، أسفلَ المقالِ والمدوّنة.
 *
 * ⚠️ **ثلاثةٌ لا واحد، لأنّ القارئَ ثلاثة.** مقالٌ عن خطّةِ مراجعةٍ يقرؤه الطالبُ
 * ووليُّ أمرِه والمدرّسُ الباحثُ عن منصّة، ونداءٌ واحدٌ («سجّلْ الآن») يُخاطِبُ
 * واحداً ويترك اثنَينِ بلا خطوةٍ تالية.
 *
 * ⚠️ **والمسارات مقيسةٌ من بناءِ الإنتاج** (`/signup/student` ·
 * `/signup/teacher` · `/signup/parent/children`)، لا مخمَّنةٌ من الأسماء: نداءٌ
 * إلى مسارٍ لا وجودَ له هو ‏٤٠٤ في نهايةِ مقالٍ أقنعَ قارئَه — أسوأُ موضعٍ
 * لرابطٍ مكسور.
 *
 * ⚠️ **و`accent` للطالبِ وحدَه**: لونُ التحويلِ في السوقِ واحد، وثلاثةُ أزرارٍ
 * بلونِ التحويلِ ثلاثةُ نداءاتٍ أُولى — أي لا نداءَ أوّلَ. والاثنانِ الآخرانِ
 * `secondary`، وهي ليست أقلَّ شأناً بل أقلُّ إلحاحاً.
 */
export function CtaBand({
  title = "ابدأْ من هنا",
  description = "اختر بابك: الطالبُ يحجز، والمدرّسُ يُدرّس، ووليُّ الأمر يتابع.",
}: {
  title?: string;
  description?: string;
}) {
  return (
    <section className="mt-16 overflow-hidden rounded-3xl border border-line bg-primary-soft p-6 sm:p-10">
      <h2 className="text-2xl font-extrabold text-ink">{title}</h2>
      <p className="mt-2 max-w-2xl leading-relaxed text-ink-muted">
        {description}
      </p>

      <div className="mt-6 flex flex-wrap gap-3">
        <Button href="/signup/student" variant="accent" size="lg" iconStart={<UserPlusIcon className="h-5 w-5" />}>
          سجّلْ كطالب
        </Button>
        <Button href="/teachers" variant="secondary" size="lg" iconStart={<AcademicCapIcon className="h-5 w-5" />}>
          تصفَّحِ المدرّسين
        </Button>
        <Button href="/signup/parent/children" variant="secondary" size="lg" iconStart={<FamilyIcon className="h-5 w-5" />}>
          حسابُ وليِّ أمر
        </Button>
      </div>

      {/*
        بابُ المدرّسِ سطرٌ لا زرٌّ رابع: أربعةُ أزرارٍ في صفٍّ واحدٍ لا تُقرَأُ
        اختياراً بل قائمة، وجمهورُ المدوّنةِ الأوّلُ طالبٌ ووليُّ أمر.
      */}
      <p className="mt-5 text-sm text-ink-muted">
        مدرّس؟{" "}
        <a
          href="/signup/teacher"
          className="font-semibold text-primary-ink underline"
        >
          قدّمْ طلبَ انضمام
        </a>
      </p>
    </section>
  );
}
