import type { Metadata } from "next";
import { publicPageMetadata } from "@/lib/seo";
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
import { platformName } from "@/lib/platform";

// Canonical and `og:url` on the bare path — see `publicPageMetadata()`.
export function generateMetadata(): Promise<Metadata> {
  return publicPageMetadata({
    path: "/pricing",
    title: "الأسعار",
    description:
      "لا اشتراك ولا رسوم تسجيل. تشتري رصيد حصص وتستهلكه حصة بحصة، والسعر يظهر كاملاً قبل الشراء.",
    image: "/marketplace/banner-pricing.webp",
  });
}

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
    title: "تُخصم عن الحصة التي حضرتها",
    /*
     ٠٣٥ · T051 — النصُّ كانَ «الخصمُ بالتسليم»، وهو ما كانَ صحيحاً حتى يومِ
     الشحن: كانَ المقعدُ المحجوزُ يُخصَمُ سواءٌ حضرَ صاحبُه أم لا. الآن الحضورُ
     هو الحدّ، ومَن أخطرَ في الوقتِ أو عُذِرَ يستردُّ تجميدَه — والمتخلّفُ بلا
     إخطارٍ يُخصَمُ منه ويُفتَحُ له المحتوى، لأنّ المقعدَ كانَ مقفولاً عن غيرِه
     ساعةً كاملة.
    */
    body: "رصيد واحد عن كل حصة حضرتها. الحصة الملغاة أو التي لم يحضرها المدرّس لا تُخصم أصلاً، ومن أخطر قبل الموعد المحدَّد يعود حجزه إلى رصيده.",
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
      "لا يُخصم رصيد عن حصة لم تُقدَّم. والخصم مرتبط بحضورك الحصة، لا بحجزها: من أخطر قبل الموعد المحدَّد أو قبِل المدرّس عذره يعود حجزه إلى رصيده، ومحتوى تلك الحصة يبقى مقفولاً حتى يوافق على خصمها.",
  },
  {
    question: "ماذا عن الحصة التجريبية؟",
    answer:
      "من يقدّمها من المدرّسين يعرضها على ملفه. اضغط «حجز حصة تجريبية» على بطاقة المدرّس لترى شروطه.",
  },
];

export default async function PricingPage() {
  const name = await platformName();

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner
        icon={TagIcon}
        image="/marketplace/banner-pricing.webp"
        title="تدفع عن الحصة التي حدثت"
        description={`لا اشتراك، ولا رسوم تسجيل، ولا عمولة تظهر عند الدفع. على ${name} تشتري رصيد حصص بإجمالٍ واحد معلن، وتستهلكه حصة بحصة.`}
      />

      {/*
        The flow is the page's one burgundy band — the home page's «كيف تعمل»
        language (brass tiles punching through a dashed rule) so the two read as
        one product. ⚠️ No step numbers, not even the home page's translucent
        ones: this page's identity is that it shows no figure at all.
      */}
      <section
        aria-labelledby="how"
        className="bg-squares relative isolate mb-16 overflow-hidden rounded-3xl bg-primary px-6 py-14 shadow-xl shadow-primary/20 sm:px-10 lg:py-16"
      >
        <div className="mx-auto mb-12 max-w-2xl text-center">
          <h2
            id="how"
            className="text-balance text-4xl font-extrabold leading-tight text-white sm:text-5xl"
          >
            كيف تُحتسب التكلفة
          </h2>
        </div>

        {/* A path, like the home page's four steps: these are three moments of
            one flow, and three identically bordered boxes would say "three
            features". The rule runs behind the markers on desktop and turns
            vertical when they stack. */}
        <div className="relative">
          <div
            className="absolute inset-x-[16.6%] top-10 hidden border-t-2 border-dashed border-white/30 sm:block"
            aria-hidden="true"
          />

          <ol className="relative grid gap-12 sm:grid-cols-3 sm:gap-6">
            {HOW_IT_WORKS.map((step, index) => {
              const Icon = step.icon;
              // The middle step is the product's position (see HOW_IT_WORKS),
              // so its tile is the one that stands taller.
              const isCentre = index === 1;

              return (
                <li
                  key={step.title}
                  className="reveal group relative flex gap-5 sm:block sm:text-center"
                >
                  {index < HOW_IT_WORKS.length - 1 && (
                    <span
                      className="absolute start-10 top-24 -bottom-12 border-s-2 border-dashed border-white/30 sm:hidden"
                      aria-hidden="true"
                    />
                  )}

                  <span
                    className={`relative grid h-20 w-20 shrink-0 place-items-center rounded-3xl bg-accent text-accent-foreground shadow-xl shadow-primary-ink/30 ring-4 ring-primary transition duration-300 ease-out group-hover:-translate-y-1.5 group-hover:rotate-3 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0 motion-reduce:group-hover:rotate-0 sm:mx-auto sm:mb-6 ${
                      isCentre ? "sm:scale-110" : ""
                    }`}
                  >
                    <Icon className="h-9 w-9" />
                  </span>

                  <span className="pt-2 sm:block sm:pt-0">
                    <span className="mb-2 block text-xl font-extrabold text-white">
                      {step.title}
                    </span>
                    <span className="mx-auto block max-w-xs leading-relaxed text-white/80">
                      {step.body}
                    </span>
                  </span>
                </li>
              );
            })}
          </ol>
        </div>

        <p className="mx-auto mt-12 max-w-2xl text-center text-sm text-white/80">
          السعر يختلف باختلاف المدرّس والكورس، ويظهر كاملاً عند اختيار الحزمة.
        </p>
      </section>

      <section
        aria-labelledby="never"
        className="relative isolate mb-16 grid gap-10 overflow-hidden rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:p-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center"
      >
        <NoCommissionIcon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-10 -start-10 -z-10 h-56 w-56 text-secondary-ink/10"
        />

        <div>
          <h2
            id="never"
            className="mb-4 text-balance text-4xl font-extrabold leading-tight text-ink sm:text-5xl"
          >
            ما لا تدفعه أبداً
          </h2>
          <p className="max-w-md text-lg leading-relaxed text-ink-muted">
            ثلاثة بنود لا توجد في هذا المنتج، لا مؤجّلة ولا مخفية في التفاصيل.
          </p>
        </div>

        <ul className="divide-y divide-line">
          {NEVER_CHARGED.map((item) => {
            const Icon = item.icon;

            return (
              <li
                key={item.title}
                className="group flex items-start gap-5 py-5 first:pt-0 last:pb-0"
              >
                {/* secondary, not primary: this is the reassuring half of the
                    page, and painting three "no" badges in the brand maroon
                    would read as three warnings. */}
                <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-secondary/10 text-secondary-ink transition duration-300 ease-out group-hover:-rotate-6 group-hover:bg-secondary group-hover:text-white motion-reduce:transition-none motion-reduce:group-hover:rotate-0">
                  <Icon className="h-7 w-7" />
                </span>
                <span>
                  <span className="mb-1 block text-xl font-extrabold text-ink">
                    {item.title}
                  </span>
                  <span className="block leading-relaxed text-ink-muted">
                    {item.body}
                  </span>
                </span>
              </li>
            );
          })}
        </ul>
      </section>

      <section
        aria-labelledby="faq"
        className="mb-16 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:gap-12"
      >
        <div className="relative isolate">
          <h2
            id="faq"
            className="text-balance text-4xl font-extrabold leading-tight text-ink sm:text-5xl lg:sticky lg:top-28"
          >
            أسئلة عن الدفع
          </h2>
          <WalletIcon
            aria-hidden="true"
            className="pointer-events-none absolute -top-6 end-0 -z-10 hidden h-40 w-40 text-primary-ink/10 lg:block"
          />
        </div>

        {/* The same accordion the home page and every teacher profile use. A
            static <dl> here meant five answers always open on a phone, and a
            second pattern for the same job on a third surface.

            ⚠️ Held to max-w-4xl inside a max-w-7xl page ON PURPOSE. The frame
            matches /teachers so every public page lines up, but an answer is
            prose: at 1280px a line runs past 150 characters, roughly double the
            65–75 the eye can track without losing its place on the return
            sweep. Matching the frame is not the same as matching the measure.
            The heading now sits beside it on wide screens, which narrows the
            column further — the cap still holds wherever it stacks. */}
        <div className="max-w-4xl">
          <FaqAccordion items={FAQ} />
        </div>
      </section>

      <section className="bg-squares relative isolate overflow-hidden rounded-3xl bg-primary px-6 py-12 text-center shadow-xl shadow-primary/20 transition-shadow duration-500 ease-out hover:shadow-2xl hover:shadow-primary/30 motion-reduce:transition-none sm:px-10 lg:py-16">
        <TagIcon
          aria-hidden="true"
          className="pointer-events-none absolute -top-8 -end-8 -z-10 h-48 w-48 text-white/10"
        />
        <h2 className="mb-4 text-balance text-3xl font-extrabold leading-tight text-white sm:text-4xl">
          الرقم الذي يخصّك يظهر على الكورس
        </h2>
        <p className="mx-auto mb-8 max-w-lg text-lg leading-relaxed text-white/85">
          اختر مدرّساً أو كورساً لترى إجمالي الحزمة كاملاً قبل أن تدفع شيئاً.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <Link
            href="/teachers"
            className="rounded-2xl bg-accent px-8 py-4 text-base font-bold text-accent-foreground shadow-lg shadow-primary/30 transition duration-300 ease-out hover:-translate-y-1 hover:shadow-xl hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
          >
            تصفّح المدرّسين
          </Link>
          <Link
            href="/courses"
            className="rounded-2xl border-2 border-white/60 px-8 py-4 text-base font-bold text-white transition duration-300 ease-out hover:-translate-y-1 hover:border-white hover:bg-white/10 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none motion-reduce:hover:translate-y-0"
          >
            تصفّح الكورسات
          </Link>
        </div>
      </section>
    </div>
  );
}
