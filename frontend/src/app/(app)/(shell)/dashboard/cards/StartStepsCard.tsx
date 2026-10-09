import Link from "next/link";
import type { ComponentType } from "react";

import { CoursesIcon, MembersIcon, PlayIcon, type IconProps } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * A new student's first screen — the review of 2026-10-09: eight empty cards
 * and three pages each naming a different place to begin.
 *
 * One road, in the order the product actually works: find a teacher, watch
 * their free recorded lesson (spec 040), join the course. The third step has no
 * link of its own because it happens on the course page the second one opens.
 */
const STEPS: { title: string; line: string; href?: string; cta?: string; Icon: ComponentType<IconProps> }[] = [
  {
    title: "اختر مادتك ومدرّسك",
    line: "تصفّح المدرّسين حسب المادة والمرحلة، واقرأ تقييماتهم ودرجة التزامهم.",
    href: "/teachers",
    cta: "تصفّح المدرّسين",
    Icon: MembersIcon,
  },
  {
    title: "شاهد حصة تجريبية مجاناً",
    line: "الكورس الذي يحمل شارة «حصة تجريبية» تشاهد درساً مسجّلاً منه مجاناً قبل أن تدفع.",
    href: "/courses",
    cta: "الكورسات",
    Icon: PlayIcon,
  },
  {
    title: "اشترك وابدأ",
    line: "من صفحة الكورس اختر مجموعتك أو حصصاً خاصة، وتظهر هنا مواعيدك ودروسك.",
    Icon: CoursesIcon,
  },
];

export function StartStepsCard() {
  return (
    <section className="banner-rise mb-6 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:p-8">
      <h2 className="text-xl font-extrabold text-ink">ابدأ من هنا</h2>
      <p className="mt-1 text-sm text-ink-muted">ثلاث خطوات بينك وبين أول درس.</p>

      <ol className="mt-6 grid gap-4 md:grid-cols-3">
        {STEPS.map((step, index) => (
          <li key={step.title} className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5">
            <div className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary text-white"
              >
                <step.Icon className="h-5 w-5" />
              </span>
              <span className="text-xs font-extrabold text-primary-ink">
                الخطوة <bdi>{arabicNumber(index + 1)}</bdi>
              </span>
            </div>
            <h3 className="text-base font-extrabold text-ink">{step.title}</h3>
            <p className="flex-1 text-sm leading-relaxed text-ink-muted">{step.line}</p>
            {step.href !== undefined && (
              <Link
                href={step.href}
                className="self-start rounded-full bg-primary-soft px-4 py-2 text-sm font-bold text-primary-ink transition-colors hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                {step.cta}
              </Link>
            )}
          </li>
        ))}
      </ol>
    </section>
  );
}
