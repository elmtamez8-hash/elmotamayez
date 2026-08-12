import type { Metadata } from "next";
import Link from "next/link";
import {
  ApplicationIcon,
  HumanReviewIcon,
  OngoingReviewIcon,
  InfoIcon,
  SecureChannelIcon,
} from "@/components/icons";
import { PageBanner } from "@/components/ui/PageBanner";
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
    icon: ApplicationIcon,
    title: "طلب من أربع خطوات",
    body: "يقدّم المدرّس بياناته الأساسية وتخصصه ومؤهلاته وأسعاره وأوقات توفّره.",
  },
  {
    icon: HumanReviewIcon,
    title: "مراجعة بشرية",
    body: "يراجع فريقنا الأكاديمي كل طلب ويقرّر: قبول، أو طلب تعديل، أو رفض مع سبب مكتوب.",
  },
  {
    icon: SecureChannelIcon,
    title: "تحقّق المستندات عبر قناة آمنة",
    body: "لا تُرفع الشهادات ولا وثائق الهوية عبر نموذج التسجيل. نطلبها لاحقاً عبر قناة مخصّصة عند الحاجة.",
  },
  {
    icon: OngoingReviewIcon,
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
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      {/* Doha's West Bay, not a generic classroom. «منصة عربية تنطلق من قطر» is
          the page's first sentence, and the skyline says it before the sentence
          is read — the one place on the site where the market is the subject. */}
      <PageBanner
        icon={InfoIcon}
        image="/marketplace/banner-about.webp"
        title={`عن ${PLATFORM_NAME}`}
        description="منصة عربية تنطلق من قطر وتخدم العالم العربي. نربط الطلاب وأولياء الأمور بمدرّسين لحصص فردية وجماعية، مباشرة ومسجّلة — والفارق الذي نراهن عليه هو أنك تعرف عن المدرّس ما يكفي قبل أن تحجز، لا بعدها."
      />

      <section aria-labelledby="vetting" className="mb-12">
        <h2 id="vetting" className="mb-5 text-2xl font-bold text-ink">
          كيف نختار المدرّسين
        </h2>

        {/* The number moved into the icon's corner rather than replacing it.
            These four are a sequence AND four different kinds of check — a bare
            counter says only the first, and four identical maroon discs say
            nothing at all about what happens at each stop. */}
        <ol className="grid gap-4 sm:grid-cols-2">
          {HOW_WE_VET.map((step, index) => {
            const Icon = step.icon;

            return (
              <li
                key={step.title}
                className="group flex gap-4 rounded-3xl border border-line bg-surface-raised p-5 transition duration-200 ease-out hover:border-primary/40 hover:shadow-md"
              >
                <span className="relative shrink-0">
                  <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-soft text-primary-ink transition duration-300 ease-out group-hover:bg-primary group-hover:text-white">
                    <Icon />
                  </span>
                  <span
                    className="absolute -top-1.5 -start-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white"
                    aria-hidden="true"
                  >
                    {(index + 1).toLocaleString("ar-QA")}
                  </span>
                </span>
                <span>
                  <span className="mb-1 block font-bold text-ink">{step.title}</span>
                  <span className="block text-sm leading-relaxed text-ink-muted">
                    {step.body}
                  </span>
                </span>
              </li>
            );
          })}
        </ol>
      </section>

      <section aria-labelledby="trust" className="mb-12">
        <h2 id="trust" className="mb-3 text-2xl font-bold text-ink">
          ما الذي تقيسه درجة الثقة
        </h2>
        <p className="mb-5 max-w-3xl leading-relaxed text-ink-muted">
          رقم من ١٠٠ نعرضه على كل ملف، ونوضّح مكوّناته بدل الاكتفاء بالرقم. المدرّس
          الجديد تظهر درجته بحالة «قيد التكوين» حتى يُكمل ١٠ حصص ويحصل على ٣
          تقييمات — لا نعرض له صفراً، لأن قلّة البيانات ليست حكماً عليه.
        </p>

        {/* The bars are a proportion, not prose, and a 1280px bar makes a 15%
            share four pixels of difference from a 25% one. Held to the width
            where the comparison is still readable. */}
        <div className="max-w-3xl rounded-3xl border border-line bg-surface-raised p-6">
          <TrustFactorBars factors={TRUST_FACTORS} />
        </div>
      </section>

      <section
        aria-labelledby="next"
        className="rounded-3xl bg-primary p-8 text-center sm:p-10"
      >
        <h2 id="next" className="mb-2 text-xl font-bold text-white sm:text-2xl">
          ابدأ من هنا
        </h2>
        {/* ⚠️ White, not `text-primary-soft`. That token is a light maroon TINT
            meant to sit under dark ink on the page surface — and dark mode
            redefines it to #331520, which is all but invisible on the #8a1538
            panel it was painted on. The panel's ground does not follow the
            theme, so its foreground must not either. */}
        <p className="mx-auto mb-6 max-w-lg text-white/85">
          تصفّح المدرّسين بلا تسجيل، أو انضم كمدرّس وقدّم طلبك للمراجعة.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <Link
            href="/teachers"
            className="rounded-full bg-surface-raised px-6 py-3 text-sm font-semibold text-primary-ink transition duration-200 ease-out hover:brightness-95 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            تصفّح المدرّسين
          </Link>
          <Link
            href="/signup/teacher"
            className="rounded-full border border-white/60 px-6 py-3 text-sm font-semibold text-white transition duration-200 ease-out hover:bg-white/10 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            انضم كمدرّس
          </Link>
        </div>
      </section>
    </div>
  );
}
