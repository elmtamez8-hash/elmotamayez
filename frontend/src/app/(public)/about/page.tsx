import type { Metadata } from "next";
import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";
import {
  TrustFactorBars,
  type TrustFactor,
} from "@/components/marketplace/TrustFactorBars";

export const metadata: Metadata = {
  title: "عن المنصة",
  description:
    `${PLATFORM_NAME} منصة عربية تنطلق من قطر تربط الطلاب وأولياء الأمور بمدرّسين يمرّون بمراجعة أكاديمية، مع درجة ثقة شفّافة لكل مدرّس.`,
};

/**
 * Everything on this page describes behaviour the platform actually has: the
 * four-step application, the human review, the factors behind the trust score.
 * Nothing here is a claim the product cannot back up — a marketing page that
 * promises more than the system does is the first thing support pays for.
 */
const HOW_WE_VET = [
  {
    title: "طلب من أربع خطوات",
    body: "يقدّم المدرّس بياناته الأساسية وتخصصه ومؤهلاته وأسعاره وأوقات توفّره.",
  },
  {
    title: "مراجعة بشرية",
    body: "يراجع فريقنا الأكاديمي كل طلب ويقرّر: قبول، أو طلب تعديل، أو رفض مع سبب مكتوب.",
  },
  {
    title: "تحقّق المستندات عبر قناة آمنة",
    body: "لا تُرفع الشهادات ولا وثائق الهوية عبر نموذج التسجيل. نطلبها لاحقاً عبر قناة مخصّصة عند الحاجة.",
  },
  {
    title: "متابعة مستمرة",
    body: "تُحتسب درجة الثقة من أداء فعلي، وتنخفض عند الشكاوى المؤكدة، ويمكن إيقاف أي مدرّس عن الظهور فوراً.",
  },
];

/*
 * Numbers, not the strings this list used to hold ("35٪"). A weight that is a
 * string can only be printed; a weight that is a number can also be drawn — and
 * these five ARE a division of one hundred, which is the fact the page promises
 * to show and a right-aligned column of percentages never does.
 *
 * The deduction is negative for the same reason: it is not a fifth share, it is
 * subtracted from what the other four earned.
 */
const TRUST_FACTORS: TrustFactor[] = [
  { label: "تقييم الطلاب", weight: 35 },
  { label: "الالتزام بالمواعيد", weight: 25 },
  { label: "إكمال الحصص دون إلغاء", weight: 25 },
  { label: "مدة العمل على المنصة", weight: 15 },
  { label: "خصم الشكاوى المؤكدة", weight: -20, note: "حتى −" },
];

export default function AboutPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="mb-4 text-3xl font-extrabold text-ink sm:text-4xl">
        عن {PLATFORM_NAME}
      </h1>

      <p className="mb-12 text-lg leading-relaxed text-ink-muted">
        منصة عربية تنطلق من قطر وتخدم العالم العربي. نربط الطلاب وأولياء الأمور
        بمدرّسين لحصص فردية وجماعية، مباشرة ومسجّلة — والفارق الذي نراهن عليه هو
        أنك تعرف عن المدرّس ما يكفي قبل أن تحجز، لا بعدها.
      </p>

      <section aria-labelledby="vetting" className="mb-12">
        <h2 id="vetting" className="mb-5 text-2xl font-bold text-ink">
          كيف نختار المدرّسين
        </h2>

        <ol className="space-y-4">
          {HOW_WE_VET.map((step, index) => (
            <li key={step.title} className="flex gap-4 rounded-xl border border-line p-5">
              <span
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white"
                aria-hidden="true"
              >
                {index + 1}
              </span>
              <span>
                <span className="block font-semibold text-ink">{step.title}</span>
                <span className="block text-ink-muted">{step.body}</span>
              </span>
            </li>
          ))}
        </ol>
      </section>

      <section aria-labelledby="trust" className="mb-12">
        <h2 id="trust" className="mb-3 text-2xl font-bold text-ink">
          ما الذي تقيسه درجة الثقة
        </h2>
        <p className="mb-5 leading-relaxed text-ink-muted">
          رقم من 100 نعرضه على كل ملف، ونوضّح مكوّناته بدل الاكتفاء بالرقم. المدرّس
          الجديد تظهر درجته بحالة «قيد التكوين» حتى يُكمل 10 حصص ويحصل على 3
          تقييمات — لا نعرض له صفراً، لأن قلّة البيانات ليست حكماً عليه.
        </p>

        <div className="rounded-3xl border border-line bg-surface-raised p-6">
          <TrustFactorBars factors={TRUST_FACTORS} />
        </div>
      </section>

      <section aria-labelledby="next" className="rounded-2xl bg-primary-soft p-6 text-center">
        <h2 id="next" className="mb-2 text-xl font-bold text-ink">
          ابدأ من هنا
        </h2>
        <p className="mb-5 text-ink-muted">
          تصفّح المدرّسين بلا تسجيل، أو انضم كمدرّس وقدّم طلبك للمراجعة.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <Link
            href="/teachers"
            className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
          >
            تصفّح المدرّسين
          </Link>
          <Link
            href="/signup/teacher"
            className="rounded-xl border border-primary px-5 py-2.5 text-sm font-semibold text-primary-ink transition hover:brightness-95"
          >
            انضم كمدرّس
          </Link>
        </div>
      </section>
    </div>
  );
}
