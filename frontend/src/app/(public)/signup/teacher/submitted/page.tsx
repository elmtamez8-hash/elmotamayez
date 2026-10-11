import type { Metadata } from "next";
import Link from "next/link";
import { SignupFrame } from "@/components/marketplace/SignupFrame";

export const metadata: Metadata = {
  title: "طلبك قيد المراجعة",
  // Not a page anyone should reach from search: it only means anything to the
  // person who just submitted.
  robots: { index: false },
};

const REVIEW_DAYS = 3;

const NEXT_STEPS = [
  "يراجع فريقنا الأكاديمي مؤهلاتك وخبرتك.",
  "قد نتواصل معك لطلب المستندات عبر قناة آمنة.",
  "يصلك القرار على بريدك الإلكتروني، وإن احتجنا تعديلاً ستجد الطلب مفتوحاً لك مرة أخرى.",
];

/** In the signup frame (owner request 2026-10-11), so the step after the form looks like the form's own page. */
export default function TeacherApplicationSubmittedPage() {
  return (
    <SignupFrame
      role="teacher"
      switcher={false}
      title="طلبك قيد المراجعة من فريقنا الأكاديمي"
      // A number, not "soon": the applicant is deciding whether to wait or look
      // elsewhere, and a vague promise is the least useful answer.
      subtitle={`عادةً نردّ خلال ${REVIEW_DAYS} أيام عمل. لا حاجة لإعادة الإرسال.`}
    >
      <h2 className="mb-5 text-lg font-extrabold text-ink">ماذا يحدث الآن؟</h2>

      <ol className="mb-8 space-y-3">
        {NEXT_STEPS.map((item, index) => (
          <li key={item} className="flex gap-3 rounded-2xl bg-primary-soft p-4 text-sm leading-relaxed text-ink">
            <span
              className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white"
              aria-hidden="true"
            >
              {index + 1}
            </span>
            {item}
          </li>
        ))}
      </ol>

      <div className="flex flex-wrap gap-3">
        <Link
          href="/teachers"
          className="rounded-full bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          تصفّح المنصة
        </Link>
        <Link
          href="/signup/teacher"
          className="rounded-full border border-line px-6 py-2.5 text-sm font-semibold text-ink transition hover:bg-primary-soft"
        >
          مراجعة طلبي
        </Link>
      </div>
    </SignupFrame>
  );
}
