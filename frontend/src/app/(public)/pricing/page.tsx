import type { Metadata } from "next";
import Link from "next/link";
import {
  NeverExpiresIcon,
  NoCommissionIcon,
  NoSignupFeeIcon,
  NoSubscriptionIcon,
  SessionChargedIcon,
  TagIcon,
  WalletIcon,
} from "@/components/icons";
import { FaqAccordion } from "@/components/marketplace/FaqAccordion";
import { PageBanner } from "@/components/ui/PageBanner";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: "الأسعار",
  description:
    "لا اشتراك ولا رسوم تسجيل. تشتري رصيد حصص وتستهلكه حصة بحصة، والسعر يظهر كاملاً قبل الشراء.",
};

/*
 * ⚠️ THIS PAGE NO LONGER QUOTES A NUMBER, AND THAT IS THE FIX (spec 006, T087).
 *
 * It used to open with "starting from X", read live from the cheapest teacher
 * via `sort=price_asc`. Both halves of that are gone: the sort is refused with a
 * 422 and `hourly_rate` is off the public payload (FR-021و) — so the page would
 * have thrown, caught, and silently rendered without its headline figure. A
 * pricing page whose price quietly disappears is worse than one that never
 * promised it.
 *
 * And the number was wrong even while it worked. A teacher's rate is what the
 * TEACHER is paid; a student pays a cost-plus total that includes the platform's
 * operating fee and the gateway's cut (FR-021). "From 90" was never a price
 * anyone was charged.
 *
 * So the page's job is not to quote — it is to make the MODEL legible. A pricing
 * page with no price has to earn its place on what it explains, which is why the
 * strongest block here is the one listing what is never charged.
 */

/**
 * ⚠️ THE ORDER IS THE ARGUMENT. Buy, spend, keep — and the middle one is the
 * product's actual position: a credit is deducted when a session is DELIVERED,
 * not when it is booked. That is the sentence a parent who has been burned by a
 * no-show is reading for, so it sits in the middle where the eye lands.
 */
const HOW_IT_WORKS = [
  {
    icon: WalletIcon,
    title: "تشتري رصيد حصص",
    body: "تختار حزمة على الكورس الذي تدرسه، فترى إجماليها كاملاً قبل الدفع — بلا رسوم تُضاف بعده.",
  },
  {
    icon: SessionChargedIcon,
    title: "تُخصم عن الحصة التي حدثت",
    body: "رصيد واحد عن كل حصة قُدِّمت فعلاً. الحصة الملغاة أو التي لم يحضرها المدرّس لا تُخصم أصلاً.",
  },
  {
    icon: NeverExpiresIcon,
    title: "رصيدك يبقى لك",
    body: "الرصيد غير المستهلَك لا ينتهي، وتراه في أي وقت في صفحة «رصيدي» مقسّماً على كل مدرّس تدرس عنده.",
  },
];

/**
 * What the platform does NOT charge.
 *
 * The strongest thing this page can say, and it is checkable: there is no
 * subscription entity in the product, registration writes no order, and the
 * student's total is one cost-plus figure computed before payment — there is no
 * later line to add a commission to.
 */
const NEVER_CHARGED = [
  {
    icon: NoSubscriptionIcon,
    title: "لا اشتراك شهري",
    body: "الحساب مجاني ويبقى مجانياً. لا شيء يُسحب منك كل شهر.",
  },
  {
    icon: NoSignupFeeIcon,
    title: "لا رسوم تسجيل",
    body: "التسجيل وتصفّح المدرّسين والاطّلاع على ملفاتهم بلا مقابل.",
  },
  {
    icon: NoCommissionIcon,
    title: "لا عمولة عند الدفع",
    body: "الإجمالي الذي تراه قبل الدفع هو ما تدفعه. لا سطر يُضاف في الخطوة الأخيرة.",
  },
];

const FAQ = [
  {
    question: "من يحدّد السعر؟",
    answer:
      "المنصة، بإجمالٍ واحد يشمل كل شيء. يظهر لك عند اختيار حزمة الأرصدة على الكورس، قبل الدفع وبلا رسوم تُضاف بعده.",
  },
  {
    question: "لماذا لا يظهر رقم على هذه الصفحة؟",
    answer:
      "لأن السعر يعتمد على المدرّس والكورس وحجم الحزمة، ورقمٌ واحد هنا سيكون خاطئاً لمعظم من يقرؤه. تراه كاملاً — قبل الدفع لا بعده — عند اختيار حزمتك على الكورس نفسه.",
  },
  {
    question: "كيف أدفع؟",
    answer:
      "بتحويل بنكي يدوي حالياً: ترفع إيصال التحويل، ويؤكّده فريق الأكاديمية قبل تفعيل التسجيل.",
  },
  {
    question: "ماذا لو أُلغيت الحصة؟",
    answer:
      "لا يُخصم رصيد عن حصة لم تُقدَّم. الخصم مرتبط بتسليم الحصة فعلياً، لا بحجزها.",
  },
  {
    question: "ماذا عن الحصة التجريبية؟",
    answer:
      "من يقدّمها من المدرّسين يعرضها على ملفه. اضغط «حجز حصة تجريبية» على بطاقة المدرّس لترى شروطه.",
  },
];

export default function PricingPage() {
  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner
        icon={TagIcon}
        image="/marketplace/banner-pricing.webp"
        title="تدفع عن الحصة التي حدثت"
        description={`لا اشتراك، ولا رسوم تسجيل، ولا عمولة تظهر عند الدفع. على ${PLATFORM_NAME} تشتري رصيد حصص بإجمالٍ واحد معلن، وتستهلكه حصة بحصة.`}
      />

      <section aria-labelledby="how" className="mb-16">
        <h2 id="how" className="mb-8 text-2xl font-bold text-ink">
          كيف تُحتسب التكلفة
        </h2>

        {/* A path, like the home page's four steps: these are three moments of
            one flow, and three identically bordered boxes would say "three
            features". The rule runs behind the markers on desktop and turns
            vertical when they stack. */}
        <div className="relative">
          <div
            className="absolute inset-x-[16.6%] top-6 hidden h-px bg-line sm:block"
            aria-hidden="true"
          />

          <ol className="relative grid gap-10 sm:grid-cols-3 sm:gap-6">
            {HOW_IT_WORKS.map((step, index) => {
              const Icon = step.icon;

              return (
                <li
                  key={step.title}
                  className="group relative flex gap-4 sm:block sm:text-center"
                >
                  {index < HOW_IT_WORKS.length - 1 && (
                    <span
                      className="absolute start-6 top-14 -bottom-10 w-px bg-line sm:hidden"
                      aria-hidden="true"
                    />
                  )}

                  <span className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-primary-ink transition duration-300 ease-out group-hover:border-primary group-hover:bg-primary group-hover:text-white sm:mx-auto sm:mb-5">
                    <Icon />
                  </span>

                  <span>
                    <span className="mb-2 block text-base font-bold text-ink">
                      {step.title}
                    </span>
                    <span className="mx-auto block max-w-xs text-sm leading-relaxed text-ink-muted">
                      {step.body}
                    </span>
                  </span>
                </li>
              );
            })}
          </ol>
        </div>

        <p className="mt-8 max-w-2xl text-sm text-ink-muted">
          السعر يختلف باختلاف المدرّس والكورس، ويظهر كاملاً عند اختيار الحزمة.
        </p>
      </section>

      <section
        aria-labelledby="never"
        className="mb-16 rounded-3xl border border-line bg-surface-raised p-6 sm:p-8"
      >
        <h2 id="never" className="mb-2 text-2xl font-bold text-ink">
          ما لا تدفعه أبداً
        </h2>
        <p className="mb-8 max-w-2xl text-ink-muted">
          ثلاثة بنود لا توجد في هذا المنتج، لا مؤجّلة ولا مخفية في التفاصيل.
        </p>

        <ul className="grid gap-6 sm:grid-cols-3">
          {NEVER_CHARGED.map((item) => {
            const Icon = item.icon;

            return (
              <li key={item.title} className="flex gap-4 sm:block">
                {/* secondary, not primary: this is the reassuring half of the
                    page, and painting three "no" badges in the brand maroon
                    would read as three warnings. */}
                <span className="mb-4 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-secondary/10 text-secondary-ink">
                  <Icon />
                </span>
                <span>
                  <span className="mb-1 block font-bold text-ink">{item.title}</span>
                  <span className="block text-sm leading-relaxed text-ink-muted">
                    {item.body}
                  </span>
                </span>
              </li>
            );
          })}
        </ul>
      </section>

      <section aria-labelledby="faq" className="mb-16">
        <h2 id="faq" className="mb-6 text-2xl font-bold text-ink">
          أسئلة عن الدفع
        </h2>

        {/* The same accordion the home page and every teacher profile use. A
            static <dl> here meant five answers always open on a phone, and a
            second pattern for the same job on a third surface.

            ⚠️ Held to max-w-4xl inside a max-w-7xl page ON PURPOSE. The frame
            matches /teachers so every public page lines up, but an answer is
            prose: at 1280px a line runs past 150 characters, roughly double the
            65–75 the eye can track without losing its place on the return
            sweep. Matching the frame is not the same as matching the measure. */}
        <div className="max-w-4xl">
          <FaqAccordion items={FAQ} />
        </div>
      </section>

      <section className="rounded-3xl bg-primary p-8 text-center sm:p-10">
        <h2 className="mb-2 text-xl font-bold text-white sm:text-2xl">
          الرقم الذي يخصّك يظهر على الكورس
        </h2>
        <p className="mx-auto mb-6 max-w-lg text-white/85">
          اختر مدرّساً أو كورساً لترى إجمالي الحزمة كاملاً قبل أن تدفع شيئاً.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <Link
            href="/teachers"
            className="rounded-full bg-surface-raised px-6 py-3 text-sm font-semibold text-primary-ink transition duration-200 ease-out hover:brightness-95 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            تصفّح المدرّسين
          </Link>
          <Link
            href="/courses"
            className="rounded-full border border-white/60 px-6 py-3 text-sm font-semibold text-white transition duration-200 ease-out hover:bg-white/10 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            تصفّح الكورسات
          </Link>
        </div>
      </section>
    </div>
  );
}
