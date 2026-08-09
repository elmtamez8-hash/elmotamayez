import type { Metadata } from "next";
import Link from "next/link";
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
 * The real figure is per package on one course, so it cannot be quoted on a
 * page that knows neither. What this page owes the reader is how the money
 * works — one total, no surprises at checkout — and where to go to see theirs.
 */

const FAQ = [
  {
    q: "هل هناك اشتراك شهري؟",
    a: "لا. الحساب مجاني، وتدفع مقابل الحصص أو الكورسات التي تحجزها فقط.",
  },
  {
    q: "من يحدّد السعر؟",
    a: "المنصة، بإجمالٍ واحد يشمل كل شيء. يظهر لك عند اختيار حزمة الأرصدة على الكورس، قبل الدفع وبلا رسوم تُضاف بعده.",
  },
  {
    q: "كيف أدفع؟",
    a: "بتحويل بنكي يدوي حالياً: ترفع إيصال التحويل، ويؤكّده فريق الأكاديمية قبل تفعيل التسجيل.",
  },
  {
    q: "ماذا عن الحصة التجريبية؟",
    a: "من يقدّمها من المدرّسين يعرضها على ملفه. اضغط «حجز حصة تجريبية» على بطاقة المدرّس لترى شروطه.",
  },
];

/**
 * The starting price comes from the live listing, not a hard-coded table: a
 * pricing page that disagrees with the teacher cards is worse than no pricing
 * page. If the API is unreachable the figure is omitted — inventing a number
 * here is the one mistake that costs trust immediately.
 *
 * "From X", not a range: the API sorts ascending by price only, and a top-end
 * figure computed from one page of results would be wrong as soon as a dearer
 * teacher joined.
 */
const HOW_IT_WORKS = [
  {
    title: "تشتري رصيد حصص",
    body: "تختار حزمة على الكورس الذي تدرسه، فترى إجماليها كاملاً قبل الدفع — بلا رسوم تُضاف بعده.",
  },
  {
    title: "تُستهلك حصة بحصة",
    body: "يُخصم رصيد واحد عن كل حصة تُقدَّم فعلاً. الحصة الملغاة أو التي لم تُقدَّم لا تُخصم.",
  },
  {
    title: "رصيدك يبقى لك",
    body: "الرصيد غير المستهلَك لا ينتهي، وتراه في أي وقت في صفحة «رصيدي» مقسّماً على كل مدرّس تدرس عنده.",
  },
];

export default function PricingPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="mb-4 text-3xl font-extrabold text-ink sm:text-4xl">الأسعار</h1>

      <p className="mb-10 text-lg leading-relaxed text-ink-muted">
        لا اشتراك، ولا رسوم تسجيل، ولا عمولة تظهر عند الدفع. على {PLATFORM_NAME}{" "}
        تشتري رصيد حصص بإجمالٍ واحد معلن، وتستهلكه حصة بحصة.
      </p>

      <section aria-labelledby="how" className="mb-10">
        <h2 id="how" className="mb-5 text-2xl font-bold text-ink">
          كيف تُحتسب التكلفة
        </h2>

        <ol className="grid gap-4 sm:grid-cols-3">
          {HOW_IT_WORKS.map((step, index) => (
            <li key={step.title} className="rounded-2xl border border-line p-5">
              <span className="mb-2 flex h-8 w-8 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary-ink">
                {(index + 1).toLocaleString("ar-QA")}
              </span>
              <h3 className="mb-1 font-semibold text-ink">{step.title}</h3>
              <p className="text-sm leading-relaxed text-ink-muted">{step.body}</p>
            </li>
          ))}
        </ol>

        <p className="mt-4 text-sm text-ink-muted">
          السعر يختلف باختلاف المدرّس والكورس، ويظهر كاملاً عند اختيار الحزمة.
        </p>
      </section>

      <section aria-labelledby="faq" className="mb-10">
        <h2 id="faq" className="mb-5 text-2xl font-bold text-ink">
          أسئلة عن الدفع
        </h2>

        <dl className="divide-y divide-line rounded-xl border border-line">
          {FAQ.map((item) => (
            <div key={item.q} className="p-5">
              <dt className="mb-1 font-semibold text-ink">{item.q}</dt>
              <dd className="leading-relaxed text-ink-muted">{item.a}</dd>
            </div>
          ))}
        </dl>
      </section>

      <div className="flex flex-wrap justify-center gap-3">
        <Link
          href="/teachers"
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          تصفّح المدرّسين
        </Link>
        <Link
          href="/courses"
          className="rounded-xl border border-primary px-5 py-2.5 text-sm font-semibold text-primary-ink transition hover:bg-primary-soft"
        >
          تصفّح الكورسات
        </Link>
      </div>
    </div>
  );
}
