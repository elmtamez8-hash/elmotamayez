import type { Metadata } from "next";
import Link from "next/link";
import { publicApi } from "@/lib/public-api";
import { CURRENCY_LABEL, PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: "الأسعار",
  description:
    "لا اشتراك ولا رسوم تسجيل. كل مدرّس يحدّد سعر حصته، وتدفع مقابل ما تحجزه فقط.",
};

const FAQ = [
  {
    q: "هل هناك اشتراك شهري؟",
    a: "لا. الحساب مجاني، وتدفع مقابل الحصص أو الكورسات التي تحجزها فقط.",
  },
  {
    q: "من يحدّد السعر؟",
    a: "المدرّس نفسه، ضمن طلب انضمامه. السعر معروض على بطاقته وعلى ملفه قبل الحجز، بلا رسوم تُضاف عند الدفع.",
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
async function startingPrice(): Promise<string | null> {
  try {
    const cheapest = await publicApi.teachers({ sort: "price_asc", per_page: "1" });

    return cheapest.data[0]?.hourly_rate ?? null;
  } catch {
    return null;
  }
}

export default async function PricingPage() {
  const from = await startingPrice();

  return (
    <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="mb-4 text-3xl font-extrabold text-ink sm:text-4xl">الأسعار</h1>

      <p className="mb-10 text-lg leading-relaxed text-ink-muted">
        لا اشتراك، ولا رسوم تسجيل، ولا عمولة تظهر عند الدفع. كل مدرّس على{" "}
        {PLATFORM_NAME} يحدّد سعر حصته، وأنت تدفع مقابل ما تحجزه.
      </p>

      {from && (
        <section
          aria-labelledby="range"
          className="mb-10 rounded-2xl border border-line p-6 text-center"
        >
          <h2 id="range" className="mb-2 text-sm font-semibold text-ink-muted">
            أسعار الحصص على المنصة تبدأ من
          </h2>
          <p className="text-3xl font-extrabold text-ink">
            {from}{" "}
            <span className="text-base font-medium text-ink-muted">
              {CURRENCY_LABEL} / الحصة
            </span>
          </p>
        </section>
      )}

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
          تصفّح المدرّسين وأسعارهم
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
