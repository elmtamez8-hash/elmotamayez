import Link from "next/link";
import {
  AcademicCapIcon,
  BookIcon,
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
 * بإطارٍ أبيضَ على الشريطِ العنّابيّ، وهي ليست أقلَّ شأناً بل أقلُّ إلحاحاً.
 */
export function CtaBand({
  title = "ابدأْ من هنا",
  description = "اختر بابك: الطالبُ يحجز، والمدرّسُ يُدرّس، ووليُّ الأمر يتابع.",
}: {
  title?: string;
  description?: string;
}) {
  /*
    ⚠️ The band is burgundy now (the home page's closing panel), so the two
    lesser doors are outlined in white rather than `Button variant="secondary"`:
    that variant paints `bg-surface-raised text-ink`, which on this band is a
    white slab competing with the brass student button. Brass still marks the
    ONE first call — the rule above holds.
  */
  const outline =
    "inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-white/50 px-6 py-3 text-base font-bold text-white transition duration-300 ease-out hover:-translate-y-0.5 hover:border-white hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0";

  return (
    <section className="bg-squares relative isolate mt-16 overflow-hidden rounded-3xl bg-primary px-6 py-12 shadow-xl shadow-primary/20 sm:px-10 lg:py-14">
      <BookIcon
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-10 -end-10 -z-10 h-56 w-56 text-white/10"
      />

      <h2 className="text-balance text-3xl font-extrabold leading-tight text-white sm:text-4xl">{title}</h2>
      <p className="mt-3 max-w-2xl text-lg leading-relaxed text-white/85">
        {description}
      </p>

      <div className="mt-8 flex flex-wrap gap-3">
        <Link
          href="/signup/student"
          className="inline-flex items-center justify-center gap-2 rounded-2xl bg-accent px-6 py-3 text-base font-bold text-accent-foreground shadow-lg shadow-primary/30 transition duration-300 ease-out hover:-translate-y-0.5 hover:shadow-xl hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        >
          <UserPlusIcon className="h-5 w-5" />
          سجّلْ كطالب
        </Link>
        <Link href="/teachers" className={outline}>
          <AcademicCapIcon className="h-5 w-5" />
          تصفَّحِ المدرّسين
        </Link>
        <Link href="/signup/parent/children" className={outline}>
          <FamilyIcon className="h-5 w-5" />
          حسابُ وليِّ أمر
        </Link>
      </div>

      {/*
        بابُ المدرّسِ سطرٌ لا زرٌّ رابع: أربعةُ أزرارٍ في صفٍّ واحدٍ لا تُقرَأُ
        اختياراً بل قائمة، وجمهورُ المدوّنةِ الأوّلُ طالبٌ ووليُّ أمر.
      */}
      <p className="mt-6 text-sm text-white/80">
        مدرّس؟{" "}
        <a
          href="/signup/teacher"
          className="font-bold text-white underline decoration-white/40 underline-offset-4 transition hover:decoration-white motion-reduce:transition-none"
        >
          قدّمْ طلبَ انضمام
        </a>
      </p>
    </section>
  );
}
