"use client";

import Link from "next/link";
import { useEffect, useState, type ComponentType } from "react";

import { CheckIcon, CoursesIcon, PlusIcon, PublishIcon, TagIcon, type IconProps } from "@/components/icons";
import { api } from "@/lib/api";
import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
import { arabicNumber } from "@/lib/numerals";
import { P, can } from "@/lib/permissions";
import { plans, type ManagedPlan } from "@/lib/plans";
import type { Course } from "@/lib/types";

type Step = { title: string; line: string; href: string; cta: string; done: boolean; Icon: ComponentType<IconProps> };

/**
 * A new teacher's road to a first sale — the journey audit of 2026-10-21: a
 * teacher approved a minute ago met nine empty cards, published a course that
 * no student could buy, and was never told that a plan waits for the platform's
 * price.
 *
 * Each step is ticked from the teacher's OWN data, and the card leaves once a
 * published course can be bought — free, or with a priced plan on offer. It is
 * the course OWNER's card (`courses.create`), so an assistant never sees it.
 */
export function TeacherStartCard() {
  const { user } = useAuth();
  const granted = user !== null && can(user, P.coursesCreate) && teachesOnPlatform(user);
  const mayPlans = can(user, P.plansManage);
  const [steps, setSteps] = useState<Step[] | null>(null);

  useEffect(() => {
    if (!granted) return;

    let live = true;

    Promise.all([
      api.get<{ data: Course[] }>("/courses?per_page=50"),
      mayPlans ? plans.manage.list() : Promise.resolve({ data: [] as ManagedPlan[] }),
    ])
      .then(([courses, offers]) => {
        if (!live) return;

        const list = courses.data ?? [];
        const published = list.filter((course) => course.status === "published");
        const priced = (offers.data ?? []).filter((plan) => plan.is_active && plan.is_priced);
        const sellable = published.some((course) => course.is_free) || (published.length > 0 && priced.length > 0);

        setSteps(
          sellable
            ? []
            : [
                {
                  title: "أنشئ كورسك الأول",
                  line: "العنوان والمادة ونوع الكورس: فردي أو جماعي أو مسجّل.",
                  href: "/manage/courses/new",
                  cta: "كورس جديد",
                  done: list.length > 0,
                  Icon: PlusIcon,
                },
                {
                  title: "أضف درساً وانشر الكورس",
                  line: "من «محتوى الكورس» أضف درساً وانشره، ثم «انشر الكورس» من صفحة تعديله.",
                  href: list[0] === undefined ? "/manage/courses" : `/manage/courses/${list[0].uuid}`,
                  cta: "كورساتي",
                  done: published.length > 0,
                  Icon: PublishIcon,
                },
                {
                  title: "أضف باقة اشتراك",
                  line: "المدة وما تغطّيه — شهر حصص خاصة لهذا الكورس مثلاً.",
                  href: "/manage/plans",
                  cta: "باقات الاشتراك",
                  done: (offers.data ?? []).length > 0,
                  Icon: TagIcon,
                },
                {
                  title: "تسعير المنصّة",
                  line: "تحدّد المنصّة سعر الباقة، ومن بعدها يشترك الطلاب. لا تحتاج إلى فعل شيء — تُعلَّم هذه الخطوة حين تُسعَّر.",
                  href: "/manage/plans",
                  cta: "حالة باقاتي",
                  done: priced.length > 0,
                  Icon: CoursesIcon,
                },
              ],
        );
      })
      // A failed read draws no card: the dashboard's other cards own their errors.
      .catch(() => live && setSteps([]));

    return () => {
      live = false;
    };
    // Booleans, not `user`: the account object is re-made on every refresh of
    // `/auth/me`, and each new one cancelled the read still in flight.
  }, [granted, mayPlans]);

  if (!granted || steps === null || steps.length === 0) return null;

  return (
    <section className="banner-rise mb-6 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:p-8">
      <h2 className="text-xl font-extrabold text-ink">ابدأ من هنا</h2>
      <p className="mt-1 text-sm text-ink-muted">أربع خطوات حتى يستطيع الطلاب الاشتراك معك.</p>

      <ol className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {steps.map((step, index) => (
          <li
            key={step.title}
            className={`flex flex-col gap-3 rounded-2xl border p-5 ${
              step.done ? "border-secondary/30 bg-secondary/5" : "border-line bg-surface"
            }`}
          >
            <div className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl ${
                  step.done ? "bg-secondary text-white" : "bg-primary text-white"
                }`}
              >
                {step.done ? <CheckIcon className="h-5 w-5" /> : <step.Icon className="h-5 w-5" />}
              </span>
              <span className="text-xs font-extrabold text-primary-ink">
                الخطوة <bdi>{arabicNumber(index + 1)}</bdi>
                {step.done && <span className="text-secondary-ink"> · تمّت</span>}
              </span>
            </div>
            <h3 className="text-base font-extrabold text-ink">{step.title}</h3>
            <p className="flex-1 text-sm leading-relaxed text-ink-muted">{step.line}</p>
            {!step.done && (
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
